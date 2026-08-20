<?php

namespace Tests\Feature\Whatsapp;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManualWhatsappSetupTest extends TestCase
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

    #[Test]
    public function it_connects_a_validated_manual_whatsapp_account_and_encrypts_its_token(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        Http::fake(function (ClientRequest $request) {
            $url = $request->url();

            if (str_contains($url, '/123456789012345/phone_numbers')) {
                return Http::response(['data' => [[
                    'id' => '109876543210987',
                    'display_phone_number' => '+1 555 010 9999',
                    'verified_name' => 'Example Business',
                    'quality_rating' => 'GREEN',
                ]]]);
            }

            if (str_contains($url, '/109876543210987')) {
                return Http::response([
                    'id' => '109876543210987',
                    'display_phone_number' => '+1 555 010 9999',
                    'verified_name' => 'Example Business',
                    'quality_rating' => 'GREEN',
                    'code_verification_status' => 'VERIFIED',
                ]);
            }

            return Http::response([
                'id' => '123456789012345',
                'name' => 'Example Business Account',
                'currency' => 'USD',
                'timezone_id' => '1',
            ]);
        });

        $token = 'manual-system-user-token-that-is-long-enough';

        $this->actingAs($user)
            ->post(route('client.whatsapp.setup.manual'), [
                'waba_id' => '123456789012345',
                'system_user_token' => $token,
                'phone_number_id' => '109876543210987',
                'app_id' => '987654321012345',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $waba = WhatsappBusinessAccount::where('waba_id', '123456789012345')->firstOrFail();

        $this->assertSame((int) $workspace->id, (int) $waba->workspace_id);
        $this->assertSame($token, $waba->credentials['system_user_token']);
        $this->assertSame('manual_setup', $waba->meta_json['connected_via']);
        $this->assertSame('987654321012345', $waba->meta_json['app_id']);
        // ⚠️ THE STORED BYTES MUST NOT CONTAIN THE TOKEN.
        //
        // This assertion was `assertNotSame($token, $raw)`, which CANNOT FAIL:
        // if the cast were lost the column would hold
        //   {"system_user_token":"manual-…","token_source":"manual_setup"}
        // — plaintext, and not identical to $token, so the comparison passed.
        // A test named "…encrypts its token" was the only thing between the
        // owner and credentials stored in the clear, and it could not detect
        // the failure it is named for.
        $raw = (string) DB::table('whatsapp_business_accounts')->where('id', $waba->id)->value('credentials');

        $this->assertStringNotContainsString($token, $raw,
            'The system user token appears verbatim in the stored column. The encrypted:array '
            .'cast is not being applied — check that `credentials` is still cast and that the '
            .'write goes through the model rather than the query builder.');

        // POSITIVE CONTROL: it is Laravel ciphertext, not merely absent. A write
        // that dropped the key entirely would satisfy the assertion above.
        $this->assertNotSame('', $raw, 'The credentials column is empty — the value was dropped, not encrypted.');
        $this->assertIsArray(json_decode(base64_decode($raw), true),
            'The stored value is not a Laravel encryption payload.');

        $this->assertDatabaseHas((new WhatsappPhoneNumber())->getTable(), [
            'waba_id_fk' => $waba->id,
            'phone_number_id' => '109876543210987',
        ]);
        $this->assertDatabaseHas((new ChannelAccount())->getTable(), [
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'phone_number_id' => '109876543210987',
            'business_account_id' => '123456789012345',
            'status' => 'active',
        ]);
    }

    #[Test]
    public function it_rejects_a_phone_number_that_is_not_assigned_to_the_waba(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();

        Http::fake([
            'https://graph.facebook.com/v20.0/123456789012345*' => Http::response([
                'id' => '123456789012345',
                'name' => 'Example Business Account',
            ]),
        ]);

        $this->actingAs($user)
            ->from(route('client.inbox.setup'))
            ->post(route('client.whatsapp.setup.manual'), [
                'waba_id' => '123456789012345',
                'system_user_token' => 'manual-system-user-token-that-is-long-enough',
                'phone_number_id' => '109876543210987',
                'app_id' => '987654321012345',
            ])
            ->assertRedirect(route('client.inbox.setup'))
            ->assertSessionHasErrors('phone_number_id');

        $this->assertDatabaseMissing('whatsapp_business_accounts', ['waba_id' => '123456789012345']);
    }
}
