<?php

namespace Tests\Feature\Flows;

use App\Models\Workspace;
use App\Modules\Flows\Models\WhatsappFlowKeyPair;
use App\Modules\Flows\Services\WhatsappFlowKeyPairService;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PublicKey;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlowDataExchangeEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WorkspaceContext::flush();
    }

    protected function tearDown(): void
    {
        WorkspaceContext::flush();
        parent::tearDown();
    }

    /** @return array{workspace:Workspace,keyPair:WhatsappFlowKeyPair} */
    private function activeKeyPair(): array
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $account = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'waba_id' => 'waba-flow-endpoint-'.$workspace->id,
            'credentials' => ['system_user_token' => 'endpoint-test-token'],
            'status' => 'active',
        ]);
        $phone = WhatsappPhoneNumber::create([
            'waba_id_fk' => $account->id,
            'phone_number_id' => 'flow-endpoint-phone-'.$workspace->id,
            'display_phone' => '+1555999'.$workspace->id,
        ]);
        $keyPair = WorkspaceContext::for($workspace->id, fn (): WhatsappFlowKeyPair => app(WhatsappFlowKeyPairService::class)->generate($phone, $workspace->id));

        return compact('workspace', 'keyPair');
    }

    /** @param array<string, mixed> $body @return array{payload:array<string,string>,aes_key:string,initial_vector:string} */
    private function metaEncryptedRequest(WhatsappFlowKeyPair $keyPair, array $body): array
    {
        $aesKey = random_bytes(16);
        $initialVector = random_bytes(16);
        $tag = '';
        $ciphertext = openssl_encrypt(
            json_encode($body, JSON_THROW_ON_ERROR),
            'aes-128-gcm',
            $aesKey,
            OPENSSL_RAW_DATA,
            $initialVector,
            $tag,
        );
        $this->assertIsString($ciphertext);

        /** @var PublicKey $publicKey */
        $publicKey = PublicKeyLoader::loadPublicKey($keyPair->public_key_pem)
            ->withPadding(RSA::ENCRYPTION_OAEP)
            ->withHash('sha256')
            ->withMGFHash('sha256');

        return [
            'payload' => [
                'encrypted_aes_key' => base64_encode($publicKey->encrypt($aesKey)),
                'encrypted_flow_data' => base64_encode($ciphertext.$tag),
                'initial_vector' => base64_encode($initialVector),
            ],
            'aes_key' => $aesKey,
            'initial_vector' => $initialVector,
        ];
    }

    /** @return array<string, mixed> */
    private function decryptMetaResponse(string $response, string $aesKey, string $initialVector): array
    {
        $envelope = base64_decode($response, true);
        $this->assertIsString($envelope);
        $tag = substr($envelope, -16);
        $ciphertext = substr($envelope, 0, -16);
        $flippedVector = $this->manuallyFlipVector($initialVector);
        $plaintext = openssl_decrypt($ciphertext, 'aes-128-gcm', $aesKey, OPENSSL_RAW_DATA, $flippedVector, $tag);

        return json_decode((string) $plaintext, true, 512, JSON_THROW_ON_ERROR);
    }

    /** Meta-side verification deliberately does not reuse production's `~$iv`. */
    private function manuallyFlipVector(string $initialVector): string
    {
        $flipped = '';
        foreach (str_split($initialVector) as $byte) {
            $flipped .= chr(255 - ord($byte));
        }

        return $flipped;
    }

    #[Test]
    public function meta_shaped_data_exchange_round_trips_through_the_public_endpoint(): void
    {
        ['keyPair' => $keyPair] = $this->activeKeyPair();
        $wire = $this->metaEncryptedRequest($keyPair, [
            'version' => '3.0',
            'action' => 'data_exchange',
            'screen' => 'PROFILE',
            'data' => ['email' => 'ada@example.test'],
            'flow_token' => 'flow-token-123',
        ]);

        $response = $this->postJson(route('public.flows.data-exchange', $keyPair->endpoint_token), $wire['payload'])
            ->assertOk()
            ->assertHeader('content-type', 'text/plain; charset=UTF-8');

        $this->assertSame([
            'screen' => 'PROFILE',
            'data' => ['acknowledged' => true],
        ], $this->decryptMetaResponse((string) $response->getContent(), $wire['aes_key'], $wire['initial_vector']));
    }

    #[Test]
    public function ping_returns_the_documented_active_health_response_inside_the_encrypted_envelope(): void
    {
        ['keyPair' => $keyPair] = $this->activeKeyPair();
        $wire = $this->metaEncryptedRequest($keyPair, [
            'version' => '3.0',
            'action' => 'ping',
            'data' => [],
            'flow_token' => 'health-check-token',
        ]);

        $response = $this->postJson(route('public.flows.data-exchange', $keyPair->endpoint_token), $wire['payload'])
            ->assertOk();

        $this->assertSame(['data' => ['status' => 'active']], $this->decryptMetaResponse(
            (string) $response->getContent(), $wire['aes_key'], $wire['initial_vector']
        ));
    }

    #[Test]
    public function unknown_or_inactive_tokens_are_the_same_generic_rejection(): void
    {
        ['keyPair' => $keyPair] = $this->activeKeyPair();
        $unknown = bin2hex(random_bytes(32));

        $this->postJson(route('public.flows.data-exchange', $unknown), [])->assertNotFound()->assertContent('');

        $keyPair->update(['status' => WhatsappFlowKeyPair::STATUS_ROTATED]);
        $this->postJson(route('public.flows.data-exchange', $keyPair->endpoint_token), [])->assertNotFound()->assertContent('');
    }

    #[Test]
    public function malformed_or_undecryptable_data_returns_metas_key_refresh_status_without_a_body(): void
    {
        ['keyPair' => $keyPair] = $this->activeKeyPair();

        $this->postJson(route('public.flows.data-exchange', $keyPair->endpoint_token), [
            'encrypted_aes_key' => 'not-base64',
            'encrypted_flow_data' => 'also-not-base64',
            'initial_vector' => 'still-not-base64',
        ])->assertStatus(421)->assertContent('');
    }

    #[Test]
    public function a_token_for_one_workspace_cannot_decrypt_another_workspaces_payload(): void
    {
        ['keyPair' => $first] = $this->activeKeyPair();
        ['keyPair' => $second] = $this->activeKeyPair();
        $wire = $this->metaEncryptedRequest($second, [
            'version' => '3.0', 'action' => 'data_exchange', 'screen' => 'PROFILE', 'data' => [], 'flow_token' => 'second',
        ]);

        $this->postJson(route('public.flows.data-exchange', $first->endpoint_token), $wire['payload'])
            ->assertStatus(421)
            ->assertContent('');

        $secondResponse = $this->postJson(route('public.flows.data-exchange', $second->endpoint_token), $wire['payload'])
            ->assertOk();
        $this->assertSame('PROFILE', $this->decryptMetaResponse(
            (string) $secondResponse->getContent(), $wire['aes_key'], $wire['initial_vector']
        )['screen']);
    }
}
