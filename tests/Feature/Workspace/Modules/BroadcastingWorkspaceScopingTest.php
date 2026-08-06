<?php

namespace Tests\Feature\Workspace\Modules;

use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\SmsProviderConfig;
use App\Modules\Broadcasting\Models\WorkspaceSmtpConfig;
use App\Modules\Shared\Models\Contact;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 1c — Broadcasting module (4 resolution sites, 1 §G-1b authorization site).
 *
 * The largest single-resolution blast radius in 1c so far. CampaignController
 * has ONE workspaceId(), reached from six places, governing all 12 of its
 * routes — and one of those six is authorise(), the module's only tenancy
 * check, shared by edit/update/show/launch/pause/destroy/test-send.
 *
 * ─── Which aborts these tests exercise ──────────────────────────────────────
 *
 * CampaignController contains 9 abort calls. Exactly ONE is a tenancy check:
 *
 *   authorise()                  abort_unless(campaign.workspace_id === resolved)  403   TESTED
 *
 * The other 8 are status/configuration guards that never look at the tenant and
 * are unaffected by this commit — they are NOT tested here:
 *
 *   edit()    abort_unless(status in draft|paused)                                 422   not tested
 *   update()  abort_unless(status in draft|paused)                                 422   not tested
 *   launch()  abort_unless(status in draft|paused)                                 422   not tested
 *   pause()   abort_unless(status in queued|sending)                               422   not tested
 *   destroy() abort_unless(status === draft)                                       422   exercised
 *                                                                                        incidentally
 *   assertWhatsAppCampaignReady()  3 × abort(422) — no client / no template /
 *                                  template not APPROVED                           422   not tested
 *
 * Counting the raw `abort` hits would have claimed 9 isolation checks in this
 * controller. There is one. The status guards are deliberately left alone: they
 * belong to campaign lifecycle tests, not to tenant isolation, and writing
 * scoping tests against them would produce assertions that pass for the wrong
 * reason (a 422 status rejection reads exactly like a 403 that never ran).
 *
 * SmsProviderController and EmailServerController have NO tenancy abort at all.
 * Their `abort_unless(in_array($provider, self::PROVIDERS), 404)` pair checks a
 * URL slug against a constant list — input validation, not tenancy. For those
 * two controllers the resolution IS the isolation, so they are tested through
 * the data they read and write rather than through a status code.
 *
 * ─── Route binding ─────────────────────────────────────────────────────────
 *
 * Campaign::getRouteKeyName() is 'uuid'. route() is given the model so it binds
 * correctly; passing ->id would 404 before authorization ever runs.
 *
 * Campaign does NOT soft-delete, so assertDatabaseHas/Missing are valid proof
 * for the destroy cases (see the CLAUDE.md trap list).
 */
class BroadcastingWorkspaceScopingTest extends TestCase
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

    private function makeCampaign(int $workspaceId, string $name, string $status = 'draft'): Campaign
    {
        return Campaign::create([
            'workspace_id' => $workspaceId,
            'name' => $name,
            'channel' => 'sms',
            'audience_type' => 'segment',
            'status' => $status,
        ]);
    }

    private function makeContact(int $workspaceId, string $phone): Contact
    {
        return Contact::create([
            'workspace_id' => $workspaceId,
            'phone_e164' => $phone,
            'first_name' => 'Test',
            'last_name' => 'Contact',
            'opt_in_sms' => true,
        ]);
    }

    // ── CampaignController: reads that no abort protects ────────────────────

    #[Test]
    public function the_campaign_list_shows_the_home_workspace_when_nothing_is_switched(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->makeCampaign($home->id, 'HomeCampaign');
        $this->makeCampaign($other->id, 'OtherCampaign');

        $body = $this->actingAs($user)->get(route('client.campaigns.index'))->assertOk()->getContent();

        $this->assertStringContainsString('HomeCampaign', $body);
        $this->assertStringNotContainsString('OtherCampaign', $body);
    }

    #[Test]
    public function the_campaign_list_follows_the_switched_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->makeCampaign($home->id, 'HomeCampaign');
        $this->makeCampaign($other->id, 'OtherCampaign');

        $body = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.campaigns.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('OtherCampaign', $body);
        $this->assertStringNotContainsString('HomeCampaign', $body);
    }

    #[Test]
    public function a_new_campaign_is_created_in_the_switched_workspace(): void
    {
        ['user' => $user, 'other' => $other] = $this->createTwoWorkspaceUser();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->post(route('client.campaigns.store'), [
                'name' => 'Created While Switched',
                'channel' => 'sms',
                'audience_type' => 'segment',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('campaigns', [
            'name' => 'Created While Switched',
            'workspace_id' => $other->id,
        ]);
    }

    /**
     * storeDraft() is the wizard's autosave. Its resolution does double duty:
     * it stamps workspace_id on the new row AND scopes the uuid lookup that
     * decides whether to update an existing draft. Before this commit both used
     * the home workspace.
     */
    #[Test]
    public function a_wizard_draft_is_created_in_the_switched_workspace(): void
    {
        ['user' => $user, 'other' => $other] = $this->createTwoWorkspaceUser();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->postJson(route('client.campaigns.store-draft'), [
                'name' => 'Draft While Switched',
                'channel' => 'email',
            ])
            ->assertOk()
            ->assertJsonStructure(['uuid']);

        $this->assertDatabaseHas('campaigns', [
            'name' => 'Draft While Switched',
            'workspace_id' => $other->id,
            'status' => 'draft',
        ]);
    }

    /**
     * The draft upsert must not reach across workspaces. Posting another
     * workspace's draft uuid while switched has to create a new row in the
     * resolved workspace, never update the foreign one.
     */
    #[Test]
    public function the_draft_upsert_will_not_update_another_workspaces_draft(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeDraft = $this->makeCampaign($home->id, 'HomeDraft');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->postJson(route('client.campaigns.store-draft'), [
                'uuid' => $homeDraft->uuid,
                'name' => 'Attempted Overwrite',
                'channel' => 'sms',
            ])
            ->assertOk();

        $this->assertSame('HomeDraft', $homeDraft->fresh()->name);
        $this->assertDatabaseHas('campaigns', [
            'name' => 'Attempted Overwrite',
            'workspace_id' => $other->id,
        ]);
    }

    /**
     * audiencePreview() counts contacts for the resolved workspace with no
     * authorization step of any kind — a wrong resolution here discloses
     * another workspace's audience size and a five-contact sample.
     */
    #[Test]
    public function the_audience_preview_counts_only_the_switched_workspaces_contacts(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->makeContact($home->id, '+8801700000001');
        $this->makeContact($home->id, '+8801700000002');
        $this->makeContact($home->id, '+8801700000003');
        $this->makeContact($other->id, '+8801700000004');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->postJson(route('client.campaigns.audience-preview'), [
                'audience_type' => 'contact_list',
                'channel' => 'sms',
            ])
            ->assertOk()
            ->assertJson(['matched' => 1, 'deliverable' => 1]);
    }

    /** POSITIVE CONTROL for the above: unswitched, it sees the home workspace's three. */
    #[Test]
    public function the_audience_preview_counts_the_home_workspaces_contacts_when_unswitched(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->makeContact($home->id, '+8801700000001');
        $this->makeContact($home->id, '+8801700000002');
        $this->makeContact($home->id, '+8801700000003');
        $this->makeContact($other->id, '+8801700000004');

        $this->actingAs($user)
            ->postJson(route('client.campaigns.audience-preview'), [
                'audience_type' => 'contact_list',
                'channel' => 'sms',
            ])
            ->assertOk()
            ->assertJson(['matched' => 3, 'deliverable' => 3]);
    }

    // ── §G-1b: authorise(), the single gate for seven endpoints ─────────────
    //
    // show() is used for the four-way core because it is the only gated route
    // with no status guard in front of the tenancy check — on edit/launch/pause
    // a 422 could mask whether authorise() ran at all.

    #[Test]
    public function viewing_another_tenants_campaign_is_blocked(): void
    {
        ['user' => $user] = $this->createTwoWorkspaceUser();
        ['home' => $foreign] = $this->createTwoWorkspaceUser(['email' => 'foreign-bc-1@example.com']);
        $campaign = $this->makeCampaign($foreign->id, 'ForeignCampaign');

        $this->actingAs($user)
            ->get(route('client.campaigns.show', $campaign))
            ->assertForbidden();
    }

    /** POSITIVE CONTROL for the above. */
    #[Test]
    public function viewing_a_campaign_in_the_home_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $campaign = $this->makeCampaign($home->id, 'MyCampaign');

        $this->actingAs($user)
            ->get(route('client.campaigns.show', $campaign))
            ->assertOk();
    }

    /** §G-1b: after switching, the switched-into workspace's campaign is viewable. */
    #[Test]
    public function viewing_a_campaign_in_the_switched_workspace_succeeds(): void
    {
        ['user' => $user, 'other' => $other] = $this->createTwoWorkspaceUser();
        $campaign = $this->makeCampaign($other->id, 'OtherCampaign');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.campaigns.show', $campaign))
            ->assertOk();
    }

    /** The converse: once switched away, the home campaign is out of scope. */
    #[Test]
    public function after_switching_a_home_workspace_campaign_is_out_of_scope(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $campaign = $this->makeCampaign($home->id, 'HomeCampaign');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.campaigns.show', $campaign))
            ->assertForbidden();
    }

    #[Test]
    public function deleting_another_tenants_campaign_is_blocked(): void
    {
        ['user' => $user] = $this->createTwoWorkspaceUser();
        ['home' => $foreign] = $this->createTwoWorkspaceUser(['email' => 'foreign-bc-2@example.com']);
        $campaign = $this->makeCampaign($foreign->id, 'ForeignCampaign');

        $this->actingAs($user)
            ->delete(route('client.campaigns.destroy', $campaign))
            ->assertForbidden();

        // Campaign does not soft-delete, so this is real proof.
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id]);
    }

    /** POSITIVE CONTROL. Also the only place the destroy status guard is exercised. */
    #[Test]
    public function deleting_a_draft_campaign_in_the_home_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $campaign = $this->makeCampaign($home->id, 'MyCampaign');

        $this->actingAs($user)
            ->delete(route('client.campaigns.destroy', $campaign))
            ->assertRedirect();

        $this->assertDatabaseMissing('campaigns', ['id' => $campaign->id]);
    }

    /**
     * test-send is the one gated route with an outbound side effect: it sends a
     * real WhatsApp/SMS/email using the campaign's workspace channel credentials.
     * Authorising it against the wrong workspace would let a switched user spend
     * another tenant's provider balance. Asserted as a 403 specifically — the
     * request must die at authorise(), before any driver is resolved.
     */
    #[Test]
    public function test_sending_another_tenants_campaign_is_blocked(): void
    {
        ['user' => $user] = $this->createTwoWorkspaceUser();
        ['home' => $foreign] = $this->createTwoWorkspaceUser(['email' => 'foreign-bc-3@example.com']);
        $campaign = $this->makeCampaign($foreign->id, 'ForeignCampaign');

        $this->actingAs($user)
            ->postJson(route('client.campaigns.test-send', $campaign), ['phone_e164' => '+8801700000000'])
            ->assertForbidden();
    }

    // ── SmsProviderController: no tenancy abort, resolution is the isolation ─

    #[Test]
    public function the_sms_gateway_page_follows_the_switched_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        SmsProviderConfig::create([
            'workspace_id' => $home->id,
            'provider' => 'twilio',
            'credentials' => ['account_sid' => 'home-sid'],
            'sender_id' => 'HOMESENDER',
        ]);
        SmsProviderConfig::create([
            'workspace_id' => $other->id,
            'provider' => 'twilio',
            'credentials' => ['account_sid' => 'other-sid'],
            'sender_id' => 'OTHERSENDER',
        ]);

        $body = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.sms-gateways.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('OTHERSENDER', $body);
        $this->assertStringNotContainsString('HOMESENDER', $body);
    }

    /**
     * The destructive counterpart. destroy() deletes by resolved workspace with
     * no ownership check — before this commit, deleting the SMS gateway while
     * switched into workspace B wiped workspace A's credentials instead.
     */
    #[Test]
    public function deleting_an_sms_gateway_while_switched_leaves_the_home_config_intact(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        SmsProviderConfig::create([
            'workspace_id' => $home->id,
            'provider' => 'twilio',
            'credentials' => ['account_sid' => 'home-sid'],
        ]);
        SmsProviderConfig::create([
            'workspace_id' => $other->id,
            'provider' => 'twilio',
            'credentials' => ['account_sid' => 'other-sid'],
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->delete(route('client.sms-gateways.destroy', 'twilio'))
            ->assertRedirect();

        $this->assertDatabaseHas('sms_provider_configs', ['workspace_id' => $home->id, 'provider' => 'twilio']);
        $this->assertDatabaseMissing('sms_provider_configs', ['workspace_id' => $other->id, 'provider' => 'twilio']);
    }

    // ── EmailServerController: same shape, worse failure mode ───────────────

    #[Test]
    public function the_email_server_page_follows_the_switched_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        WorkspaceSmtpConfig::create([
            'workspace_id' => $home->id,
            'host' => 'smtp.home-workspace.test',
            'port' => 587,
            'username' => 'home',
            'password' => 'secret',
            'encryption' => 'tls',
            'from_email' => 'home@example.com',
            'from_name' => 'Home',
            'is_active' => true,
        ]);
        WorkspaceSmtpConfig::create([
            'workspace_id' => $other->id,
            'host' => 'smtp.other-workspace.test',
            'port' => 587,
            'username' => 'other',
            'password' => 'secret',
            'encryption' => 'tls',
            'from_email' => 'other@example.com',
            'from_name' => 'Other',
            'is_active' => true,
        ]);

        $body = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.email-server.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('smtp.other-workspace.test', $body);
        $this->assertStringNotContainsString('smtp.home-workspace.test', $body);
    }

    /**
     * store() DELETES every existing config for the resolved workspace before
     * writing the new one. With the old resolution, saving SMTP settings while
     * switched into workspace B destroyed workspace A's mail configuration and
     * silently wrote B's credentials onto A.
     */
    #[Test]
    public function saving_smtp_while_switched_does_not_destroy_the_home_workspace_config(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        WorkspaceSmtpConfig::create([
            'workspace_id' => $home->id,
            'host' => 'smtp.home-workspace.test',
            'port' => 587,
            'username' => 'home',
            'password' => 'secret',
            'encryption' => 'tls',
            'from_email' => 'home@example.com',
            'from_name' => 'Home',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->post(route('client.email-server.store'), [
                'host' => 'smtp.switched.test',
                'port' => 465,
                'username' => 'switched',
                'password' => 'secret',
                'encryption' => 'ssl',
                'from_email' => 'switched@example.com',
                'from_name' => 'Switched',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('workspace_smtp_configs', [
            'workspace_id' => $home->id,
            'host' => 'smtp.home-workspace.test',
        ]);
        $this->assertDatabaseHas('workspace_smtp_configs', [
            'workspace_id' => $other->id,
            'host' => 'smtp.switched.test',
        ]);
    }
}
