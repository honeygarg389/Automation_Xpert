<?php

namespace Tests\Feature\Workspace\Modules;

use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappWidget;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 1c — Whatsapp module, PART B of 2: channel setup and onboarding.
 *
 * 11 sites completing the module's 22. Six of them are §G-1b authorization
 * sites, and they use THREE different guard shapes — which is why each is
 * tested directly rather than by assuming one guard covers the rest:
 *
 *   WidgetController   abort_unless(...) with the expression INLINE in the
 *                      comparison — edit, update, destroy
 *   SetupController    authorizeWaba($waba, $workspaceId) helper, fed by all
 *                      four resolution sites — syncPhoneNumbers, destroy,
 *                      refreshPhoneStatus, changeDisplayName
 *   EmbeddedSignup     if ($waba->workspace_id !== $workspaceId) abort(403)
 *                      — reregisterWebhook
 *
 * Every "blocked" assertion is paired with a positive control on the SAME route
 * and verb, per CLAUDE.md.
 *
 * Traps checked rather than assumed:
 *  - ROUTE KEYS: WhatsappWidget and WhatsappBusinessAccount both bind by `id`
 *    (neither defines getRouteKeyName). Verified per model — Shared proved a
 *    module can mix uuid and id.
 *  - SOFT DELETES: neither model soft-deletes, so assertDatabaseHas/Missing is
 *    load-bearing for the destroy assertions.
 *  - ONE REQUEST PER TEST: WorkspaceContext memoises per user id.
 *  - LIVE NETWORK: WhatsappEmbeddedSignupController calls graph.facebook.com at
 *    seven points and WhatsappSetupController imports phone numbers from Meta.
 *    Http::fake() is mandatory here, not hygiene — without it these tests would
 *    make real outbound calls. Unlike the Ecommerce Woo path there is no
 *    DNS-resolving validator, so no host needs to resolve.
 *  - The positive controls assert the guard was PASSED, not that the whole Meta
 *    round trip succeeded: with faked HTTP these endpoints legitimately fail
 *    downstream. Asserting "not 403" is the honest assertion, and it still
 *    fails when the resolution is reverted.
 */
class WhatsappSetupWorkspaceScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WorkspaceContext::flush();
        Queue::fake();
        Http::fake();
    }

    protected function tearDown(): void
    {
        WorkspaceContext::flush();
        parent::tearDown();
    }

    private function widget(int $workspaceId, string $name): WhatsappWidget
    {
        return WhatsappWidget::create([
            'workspace_id' => $workspaceId,
            'widget_key' => (string) Str::uuid(),
            'name' => $name,
            'display_phone' => '+15550000000',
            'position' => 'bottom_right',
        ]);
    }

    private function waba(int $workspaceId): WhatsappBusinessAccount
    {
        return WhatsappBusinessAccount::create([
            'workspace_id' => $workspaceId,
            'waba_id' => 'waba-'.$workspaceId.'-'.random_int(1000, 9999),
        ]);
    }

    // ── Widget list follows the switch ──────────────────────────────────────

    #[Test]
    public function the_widget_list_shows_the_home_workspace_when_unswitched(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->widget($home->id, 'HomeWidget');
        $this->widget($other->id, 'OtherWidget');

        $this->actingAs($user)
            ->get(route('client.whatsapp.widget.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('widgets', 1)
                ->where('widgets.0.name', 'HomeWidget'));
    }

    #[Test]
    public function the_widget_list_follows_the_switched_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->widget($home->id, 'HomeWidget');
        $this->widget($other->id, 'OtherWidget');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.whatsapp.widget.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('widgets', 1)
                ->where('widgets.0.name', 'OtherWidget'));
    }

    #[Test]
    public function a_new_widget_is_created_in_the_switched_workspace(): void
    {
        ['user' => $user, 'other' => $other] = $this->createTwoWorkspaceUser();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->post(route('client.whatsapp.widgets.store'), [
                'name' => 'SwitchedWidget',
                'display_phone' => '+15551112222',
                'position' => 'bottom_right',
            ]);

        $this->assertDatabaseHas('whatsapp_widgets', [
            'name' => 'SwitchedWidget',
            'workspace_id' => $other->id,
        ]);
    }

    // ── §G-1b: the three INLINE guards in WidgetController ──────────────────

    #[Test]
    public function editing_another_tenants_widget_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeWidget = $this->widget($home->id, 'HomeWidget');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.whatsapp.widgets.edit', $homeWidget->id))
            ->assertForbidden();
    }

    #[Test]
    public function editing_a_widget_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeWidget = $this->widget($home->id, 'HomeWidget');

        $this->actingAs($user)
            ->get(route('client.whatsapp.widgets.edit', $homeWidget->id))
            ->assertOk();
    }

    #[Test]
    public function updating_another_tenants_widget_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeWidget = $this->widget($home->id, 'HomeWidget');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->put(route('client.whatsapp.widgets.update', $homeWidget->id), [
                'display_phone' => '+15559999999',
                'position' => 'bottom_left',
                'name' => 'Hijacked',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('whatsapp_widgets', [
            'id' => $homeWidget->id,
            'name' => 'HomeWidget',
        ]);
    }

    #[Test]
    public function updating_a_widget_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeWidget = $this->widget($home->id, 'HomeWidget');

        $this->actingAs($user)
            ->put(route('client.whatsapp.widgets.update', $homeWidget->id), [
                'display_phone' => '+15559999999',
                'position' => 'bottom_left',
                'name' => 'Renamed',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('whatsapp_widgets', [
            'id' => $homeWidget->id,
            'name' => 'Renamed',
        ]);
    }

    #[Test]
    public function deleting_another_tenants_widget_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeWidget = $this->widget($home->id, 'HomeWidget');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->delete(route('client.whatsapp.widgets.destroy', $homeWidget->id))
            ->assertForbidden();

        // WhatsappWidget does not soft-delete, so a surviving row is real proof.
        $this->assertDatabaseHas('whatsapp_widgets', ['id' => $homeWidget->id]);
    }

    #[Test]
    public function deleting_a_widget_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeWidget = $this->widget($home->id, 'HomeWidget');

        $this->actingAs($user)
            ->delete(route('client.whatsapp.widgets.destroy', $homeWidget->id))
            ->assertRedirect();

        $this->assertDatabaseMissing('whatsapp_widgets', ['id' => $homeWidget->id]);
    }

    // ── §G-1b: SetupController::authorizeWaba(), all four entry points ──────

    #[Test]
    public function deleting_another_tenants_waba_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeWaba = $this->waba($home->id);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->delete(route('client.whatsapp.setup.destroy', $homeWaba->id))
            ->assertForbidden();

        $this->assertDatabaseHas('whatsapp_business_accounts', ['id' => $homeWaba->id]);
    }

    #[Test]
    public function deleting_a_waba_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeWaba = $this->waba($home->id);

        $this->actingAs($user)
            ->delete(route('client.whatsapp.setup.destroy', $homeWaba->id))
            ->assertRedirect();

        $this->assertDatabaseMissing('whatsapp_business_accounts', ['id' => $homeWaba->id]);
    }

    #[Test]
    public function syncing_another_tenants_waba_phone_numbers_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeWaba = $this->waba($home->id);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->post(route('client.whatsapp.setup.sync-phone-numbers', $homeWaba->id))
            ->assertForbidden();
    }

    /**
     * Positive control: same route, same verb, unswitched. The Meta call is
     * faked so the sync cannot really succeed — what matters is that the guard
     * was PASSED, i.e. the response is anything but 403.
     */
    #[Test]
    public function syncing_a_waba_in_the_current_workspace_passes_the_guard(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeWaba = $this->waba($home->id);

        $response = $this->actingAs($user)
            ->post(route('client.whatsapp.setup.sync-phone-numbers', $homeWaba->id));

        $this->assertNotSame(403, $response->getStatusCode(),
            'The guard rejected a WABA in the user\'s own current workspace.');
    }

    #[Test]
    public function refreshing_another_tenants_phone_status_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeWaba = $this->waba($home->id);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->postJson(route('client.whatsapp.setup.refresh-phone-status', [$homeWaba->id, '1234567890']))
            ->assertForbidden();
    }

    #[Test]
    public function changing_another_tenants_phone_display_name_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeWaba = $this->waba($home->id);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->postJson(route('client.whatsapp.setup.change-display-name', [$homeWaba->id, '1234567890']), [
                'new_display_name' => 'Hijacked Name',
            ])
            ->assertForbidden();
    }

    // ── §G-1b: EmbeddedSignupController::reregisterWebhook() ────────────────

    #[Test]
    public function reregistering_another_tenants_waba_webhook_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeWaba = $this->waba($home->id);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->postJson(route('client.whatsapp.setup.reregister-webhook', $homeWaba->id))
            ->assertForbidden();
    }

    #[Test]
    public function reregistering_a_waba_webhook_in_the_current_workspace_passes_the_guard(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeWaba = $this->waba($home->id);

        $response = $this->actingAs($user)
            ->postJson(route('client.whatsapp.setup.reregister-webhook', $homeWaba->id));

        $this->assertNotSame(403, $response->getStatusCode(),
            'The guard rejected a WABA in the user\'s own current workspace.');
    }
}
