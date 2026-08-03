<?php

namespace Tests\Feature\Meta;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Services\WebhookIdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetaInboundWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const APP_ID = 'test_meta_app_id';

    private const APP_SECRET = 'test_meta_app_secret';

    private function seedMetaIntegration(array $extra = []): IntegrationConfig
    {
        return IntegrationConfig::create([
            'provider' => 'meta_app',
            'label' => 'Meta App',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => array_merge([
                'app_id' => self::APP_ID,
                'app_secret' => self::APP_SECRET,
                'verify_token' => 'meta-verify-token-xyz',
            ], $extra),
        ]);
    }

    private function globalVerifyToken(): string
    {
        return hash('sha256', self::APP_ID.self::APP_SECRET.'wh_global_verify');
    }

    private function signPayload(string $payload): string
    {
        return 'sha256='.hash_hmac('sha256', $payload, self::APP_SECRET);
    }

    #[Test]
    public function global_whatsapp_webhook_verifies_hub_challenge(): void
    {
        $this->seedMetaIntegration();

        $qs = http_build_query([
            'hub_mode' => 'subscribe',
            'hub_verify_token' => $this->globalVerifyToken(),
            'hub_challenge' => 'challenge_global_99',
        ]);

        $this->get("/webhooks/whatsapp/global?{$qs}")
            ->assertOk()
            ->assertSee('challenge_global_99');
    }

    #[Test]
    public function global_whatsapp_webhook_rejects_invalid_signature(): void
    {
        $this->seedMetaIntegration();

        $this->withHeaders(['X-Hub-Signature-256' => 'sha256=bad'])
            ->postJson('/webhooks/whatsapp/global', ['object' => 'whatsapp_business_account', 'entry' => []])
            ->assertUnauthorized();
    }

    #[Test]
    public function global_whatsapp_webhook_accepts_valid_signature(): void
    {
        $this->seedMetaIntegration();
        $payload = json_encode(['object' => 'whatsapp_business_account', 'entry' => []]);

        $this->withHeaders(['X-Hub-Signature-256' => $this->signPayload($payload)])
            ->postJson('/webhooks/whatsapp/global', json_decode($payload, true))
            ->assertOk()
            ->assertJson(['status' => 'ok']);
    }

    #[Test]
    public function meta_instagram_webhook_verifies_and_accepts_signature(): void
    {
        $this->seedMetaIntegration();
        $token = 'meta-verify-token-xyz';

        $qs = http_build_query([
            'hub_mode' => 'subscribe',
            'hub_verify_token' => $token,
            'hub_challenge' => 'ig_challenge',
        ]);
        $this->get("/webhooks/meta/{$token}?{$qs}")
            ->assertOk()
            ->assertSee('ig_challenge');

        $payload = json_encode([
            'object' => 'instagram',
            'entry' => [['id' => '123', 'messaging' => []]],
        ]);

        $this->withHeaders(['X-Hub-Signature-256' => $this->signPayload($payload)])
            ->postJson("/webhooks/meta/{$token}", json_decode($payload, true))
            ->assertOk();
    }

    #[Test]
    public function meta_webhook_rejects_wrong_verify_token_in_url(): void
    {
        $this->seedMetaIntegration();

        $this->get('/webhooks/meta/wrong-token?hub_mode=subscribe&hub_verify_token=wrong')
            ->assertForbidden();
    }

    #[Test]
    public function per_waba_token_lookup_uses_hash_not_full_table_scan(): void
    {
        $user = $this->createWorkspaceContext();
        $token = 'unique-per-waba-token-abc';

        WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $user['workspace']->id,
            'webhook_verify_token' => $token,
            'status' => 'active',
        ]);

        $waba = WhatsappBusinessAccount::findByWebhookToken($token);
        $this->assertNotNull($waba);
        $this->assertEquals(
            WhatsappBusinessAccount::hashWebhookToken($token),
            $waba->webhook_verify_token_hash
        );
    }

    #[Test]
    public function whatsapp_driver_updates_template_status_from_webhook(): void
    {
        $user = $this->createWorkspaceContext();
        $wabaId = 'WABA_TPL_TEST';

        WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $user['workspace']->id,
            'waba_id' => $wabaId,
        ]);

        WhatsappTemplate::create([
            'workspace_id' => $user['workspace']->id,
            'waba_id' => $wabaId,
            'name' => 'hello_world',
            'language' => 'en',
            'status' => 'PENDING',
        ]);

        $payload = [
            'entry' => [[
                'id' => $wabaId,
                'changes' => [[
                    'field' => 'message_template_status_update',
                    'value' => [
                        'event' => 'APPROVED',
                        'message_template_name' => 'hello_world',
                        'message_template_language' => 'en',
                    ],
                ]],
            ]],
        ];

        app(\App\Modules\Whatsapp\Services\WhatsappDriver::class)->processWebhookPayload($payload);

        $this->assertDatabaseHas('whatsapp_templates', [
            'waba_id' => $wabaId,
            'name' => 'hello_world',
            'status' => 'APPROVED',
        ]);
    }

    // ── Inbound webhook idempotency ──────────────────────────────────────────
    //
    // The previous version of this test posted an entry with `changes => []`.
    // entryEventKey() returns null for that (there is nothing to key on), so the
    // `$eventKey === null ||` short-circuit in receiveGlobal meant isNewEvent()
    // was never called and no row was ever written. It asserted 1 row, found 0,
    // and had never exercised deduplication at all.
    //
    // These tests use realistic payloads so the m:<message-id> and
    // s:<status-id>:<status> keying in entryEventKey() is genuinely covered.

    /** Build a signed WhatsApp webhook body containing one inbound message. */
    private function messageEntry(string $messageId, string $wabaId = 'waba_1'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $wabaId,
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '15550001111', 'phone_number_id' => 'pn_1'],
                        'contacts' => [['profile' => ['name' => 'Test'], 'wa_id' => '15550002222']],
                        'messages' => [[
                            'from' => '15550002222',
                            'id' => $messageId,
                            'timestamp' => (string) time(),
                            'type' => 'text',
                            'text' => ['body' => 'hello'],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    /** Build a signed WhatsApp webhook body carrying one status transition. */
    private function statusEntry(string $messageId, string $status, string $wabaId = 'waba_1'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $wabaId,
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '15550001111', 'phone_number_id' => 'pn_1'],
                        'statuses' => [[
                            'id' => $messageId,
                            'status' => $status,
                            'timestamp' => (string) time(),
                            'recipient_id' => '15550002222',
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function postSignedWebhook(array $body): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['X-Hub-Signature-256' => $this->signPayload(json_encode($body))])
            ->postJson('/webhooks/whatsapp/global', $body);
    }

    /**
     * Rows recorded by the CONTROLLER's idempotency check specifically.
     *
     * There are two independent layers, and a bare assertDatabaseCount conflates
     * them: WhatsappWebhookController records under 'whatsapp_global', and
     * WhatsappDriver records again under 'whatsapp_msg' keyed on the raw message
     * id. Tests run with QUEUE_CONNECTION=sync, so the queued job executes inline
     * and both fire — an inbound message therefore writes two rows, a status
     * transition only one.
     */
    private function globalEventCount(): int
    {
        return (int) \Illuminate\Support\Facades\DB::table('inbound_webhook_events')
            ->where('provider', 'whatsapp_global')
            ->count();
    }

    /** NEGATIVE: the same message delivered twice must be recorded once. */
    #[Test]
    public function global_webhook_dedupes_a_redelivered_message(): void
    {
        $this->seedMetaIntegration();
        $body = $this->messageEntry('wamid.DUPLICATE_TEST_1');

        $this->postSignedWebhook($body)->assertOk();
        $this->postSignedWebhook($body)->assertOk();

        $this->assertSame(1, $this->globalEventCount(), 'A redelivered message must not be recorded twice.');
    }

    /**
     * POSITIVE CONTROL for the test above.
     *
     * Without this, "1 row after two posts" is equally consistent with the
     * endpoint recording nothing at all — which is exactly how the previous
     * version of this test passed review while verifying nothing. Two genuinely
     * distinct messages must produce two rows.
     */
    #[Test]
    public function global_webhook_records_two_distinct_messages_separately(): void
    {
        $this->seedMetaIntegration();

        $this->postSignedWebhook($this->messageEntry('wamid.DISTINCT_A'))->assertOk();
        $this->postSignedWebhook($this->messageEntry('wamid.DISTINCT_B'))->assertOk();

        $this->assertSame(2, $this->globalEventCount(), 'Two distinct messages must both be recorded.');
    }

    /**
     * A message moves sent → delivered → read. entryEventKey() keys statuses on
     * id + status precisely so each transition is processed while a re-delivery
     * of the same transition dedupes. That claim lives in a code comment and had
     * never been verified.
     */
    #[Test]
    public function global_webhook_treats_each_status_transition_as_distinct(): void
    {
        $this->seedMetaIntegration();
        $messageId = 'wamid.STATUS_FLOW_1';

        foreach (['sent', 'delivered', 'read'] as $status) {
            $this->postSignedWebhook($this->statusEntry($messageId, $status))->assertOk();
        }

        $this->assertSame(3, $this->globalEventCount(), 'sent/delivered/read must each be recorded.');
    }

    /** ...and a re-delivered status transition must still dedupe. */
    #[Test]
    public function global_webhook_dedupes_a_redelivered_status_transition(): void
    {
        $this->seedMetaIntegration();
        $body = $this->statusEntry('wamid.STATUS_DUP_1', 'delivered');

        $this->postSignedWebhook($body)->assertOk();
        $this->postSignedWebhook($body)->assertOk();

        $this->assertSame(1, $this->globalEventCount(), 'A redelivered status transition must dedupe.');
    }

    /**
     * The second idempotency layer, asserted explicitly so its existence is
     * documented by a test rather than only discoverable by grep.
     */
    #[Test]
    public function inbound_message_is_recorded_by_both_idempotency_layers(): void
    {
        $this->seedMetaIntegration();

        $this->postSignedWebhook($this->messageEntry('wamid.TWO_LAYER_1'))->assertOk();

        $this->assertDatabaseHas('inbound_webhook_events', ['provider' => 'whatsapp_global']);
        $this->assertDatabaseHas('inbound_webhook_events', ['provider' => 'whatsapp_msg']);
    }
}
