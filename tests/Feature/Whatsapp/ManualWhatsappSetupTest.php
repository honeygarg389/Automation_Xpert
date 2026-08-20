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
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $waba = WhatsappBusinessAccount::where('waba_id', '123456789012345')->firstOrFail();

        $this->assertSame((int) $workspace->id, (int) $waba->workspace_id);
        $this->assertSame($token, $waba->credentials['system_user_token']);
        $this->assertSame('manual_setup', $waba->meta_json['connected_via']);
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

    /** The Meta responses a well-formed connection produces. */
    private function fakeMetaSuccess(): void
    {
        Http::fake(function (ClientRequest $request) {
            $url = $request->url();

            if (str_contains($url, '/123456789012345/phone_numbers')) {
                return Http::response(['data' => [[
                    'id' => '109876543210987',
                    'display_phone_number' => '+1 555 010 9999',
                    'verified_name' => 'Example Business',
                ]]]);
            }

            if (str_contains($url, '/109876543210987')) {
                return Http::response(['id' => '109876543210987', 'verified_name' => 'Example Business']);
            }

            return Http::response(['id' => '123456789012345', 'name' => 'Example Business Account']);
        });
    }

    /** @return array<string, string> A valid payload. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'waba_id' => '123456789012345',
            'system_user_token' => 'manual-system-user-token-that-is-long-enough',
            'phone_number_id' => '109876543210987',
        ], $overrides);
    }

    // ══ ⚠️ THE REFUSAL PATHS — none of these had ever executed ══════════════

    /**
     * ⚠️ BUG-019, AND THIS IS THE FIRST PATH WHERE A CUSTOMER CAN TYPE THE KEY.
     *
     * Embedded signup returns a phone_number_id from Meta's own OAuth flow, so a
     * cross-workspace collision needed two customers with a shared Meta asset. A
     * paste box accepts ANY string, including a number another workspace already
     * holds — a typo is enough.
     *
     * The refusal has to come from resolveForAttach(). A direct ChannelAccount
     * create would hit the unique index and surface as a duplicate-key 500,
     * which reads as "the product is broken" rather than the deliberate refusal
     * the BUG-019 ruling chose.
     */
    #[Test]
    public function it_refuses_a_phone_number_already_held_by_another_workspace(): void
    {
        ['workspace' => $otherWorkspace] = $this->createWorkspaceContext();
        ChannelAccount::withoutWorkspaceScope('reason: fixture for a cross-tenant collision')->create([
            'workspace_id' => $otherWorkspace->id,
            'channel' => 'whatsapp',
            'provider' => 'meta',
            'phone_number_id' => '109876543210987',
            'display_name' => 'Held elsewhere',
            'status' => 'active',
        ]);

        ['user' => $user] = $this->createWorkspaceContext();
        $this->fakeMetaSuccess();

        $this->actingAs($user)
            ->from(route('client.inbox.setup'))
            ->post(route('client.whatsapp.setup.manual'), $this->payload())
            ->assertRedirect(route('client.inbox.setup'))
            ->assertSessionHasErrors('phone_number_id');

        // ⚠️ Nothing persisted. A refusal that still wrote the WABA would leave
        // a half-connected account the customer cannot see or remove.
        $this->assertDatabaseMissing('whatsapp_business_accounts', ['waba_id' => '123456789012345']);
    }

    /**
     * POSITIVE CONTROL for the test above: same route, same verb, same user
     * type, and it SUCCEEDS when the number is free. Without this, a 500 or a
     * route-binding 404 would satisfy the refusal assertion equally well.
     */
    #[Test]
    public function the_same_request_succeeds_when_the_phone_number_is_not_held_elsewhere(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $this->fakeMetaSuccess();

        $this->actingAs($user)
            ->post(route('client.whatsapp.setup.manual'), $this->payload())
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('whatsapp_business_accounts', [
            'waba_id' => '123456789012345',
            'workspace_id' => $workspace->id,
        ]);
    }

    /** The WABA itself may not be claimed by a different workspace either. */
    #[Test]
    public function it_refuses_a_waba_already_connected_to_another_workspace(): void
    {
        ['workspace' => $otherWorkspace] = $this->createWorkspaceContext();
        WhatsappBusinessAccount::create([
            'workspace_id' => $otherWorkspace->id,
            'waba_id' => '123456789012345',
            'credentials' => ['system_user_token' => 'someone-elses-token-value-here'],
            'webhook_verify_token' => 'other-workspace-verify-token',
            'status' => 'active',
        ]);

        ['user' => $user] = $this->createWorkspaceContext();
        $this->fakeMetaSuccess();

        $this->actingAs($user)
            ->from(route('client.inbox.setup'))
            ->post(route('client.whatsapp.setup.manual'), $this->payload())
            ->assertRedirect(route('client.inbox.setup'))
            ->assertSessionHasErrors('waba_id');

        // The other workspace's row is untouched — not re-pointed, not reused.
        $this->assertSame(
            (int) $otherWorkspace->id,
            (int) WhatsappBusinessAccount::where('waba_id', '123456789012345')->value('workspace_id'),
        );
        $this->assertSame(1, WhatsappBusinessAccount::where('waba_id', '123456789012345')->count());
    }

    /**
     * ⚠️ Meta answering 200 is not Meta confirming the ID.
     *
     * A token valid for a DIFFERENT WABA returns a successful response
     * describing that other account. Without the id comparison the connection
     * would be stored against an ID whose token cannot read it, and every send
     * would fail later with no clue why.
     */
    #[Test]
    public function it_refuses_when_meta_returns_a_different_waba_id(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();

        Http::fake(fn () => Http::response(['id' => '999999999999999', 'name' => 'A Different Account']));

        $this->actingAs($user)
            ->from(route('client.inbox.setup'))
            ->post(route('client.whatsapp.setup.manual'), $this->payload())
            ->assertRedirect(route('client.inbox.setup'))
            ->assertSessionHasErrors('waba_id');

        $this->assertDatabaseMissing('whatsapp_business_accounts', ['waba_id' => '123456789012345']);
    }

    // ══ ⚠️ $dontFlash ══════════════════════════════════════════════════════

    /**
     * ⚠️ A VALIDATION FAILURE MUST NOT WRITE THE TOKEN TO THE SESSION.
     *
     * Laravel flashes `Arr::except($request->input(), $dontFlash)` on a
     * ValidationException, and the framework default covers only password
     * fields. The session driver here is `database` with `session.encrypt =
     * false`, so anything flashed lands in the `sessions` table as base64 of
     * serialized PHP — recoverable by anyone who can read that table.
     *
     * The trigger is mundane: one non-numeric character in the phone id.
     */
    #[Test]
    public function a_validation_failure_does_not_flash_the_token_into_the_session(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();

        $token = 'manual-system-user-token-that-is-long-enough';

        $response = $this->actingAs($user)
            ->from(route('client.inbox.setup'))
            ->post(route('client.whatsapp.setup.manual'), $this->payload([
                'phone_number_id' => 'not-a-number',
                'system_user_token' => $token,
                // ⚠️ THE CONTROL, and the first version of this test needed it.
                //
                // All three real fields are in dontFlash, so _old_input comes
                // back empty and every assertNotHasKey below passes trivially —
                // it would pass just as happily if flashing were disabled
                // outright, or if the request never reached the validator.
                // A benign extra key proves flashing DID run for this request.
                'ui_scroll_position' => '240',
            ]));

        $response->assertSessionHasErrors('phone_number_id');

        $old = session()->get('_old_input', []);

        $this->assertArrayHasKey('ui_scroll_position', $old,
            'Nothing was flashed at all, so the exclusions below prove nothing.');

        $this->assertArrayNotHasKey('system_user_token', $old,
            'The Meta system user token was flashed into the session. session.encrypt is false, '
            .'so it is recoverable in plaintext from the sessions table.');
        $this->assertArrayNotHasKey('waba_id', $old);
        $this->assertArrayNotHasKey('phone_number_id', $old);

        // And the token must not appear anywhere in the serialized bag, whatever
        // key it might have been nested under.
        $this->assertStringNotContainsString($token, serialize($old));
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
            ])
            ->assertRedirect(route('client.inbox.setup'))
            ->assertSessionHasErrors('phone_number_id');

        $this->assertDatabaseMissing('whatsapp_business_accounts', ['waba_id' => '123456789012345']);
    }
}
