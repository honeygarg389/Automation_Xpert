<?php

namespace Tests\Feature\Workspace\Modules;

use App\Modules\Automation\Models\Automation;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 1c — Automation module (1 resolution site, 6 §G-1b authorization sites).
 *
 * AutomationController::workspaceId() feeds index/store AND authorise(), which
 * is the single gate for edit, update, destroy, runs, generateToken and test.
 * Before this commit that one check compared against the user's HOME workspace
 * regardless of which workspace they had switched into — so a single wrong
 * value governed six endpoints.
 *
 * Automation binds by uuid (getRouteKeyName), so route() is given the model and
 * resolves it correctly; passing ->id would 404 before authorization (see
 * §G-1c for how that once hid three isolation tests).
 */
class AutomationWorkspaceScopingTest extends TestCase
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

    private function makeAutomation(int $workspaceId, string $name): Automation
    {
        return Automation::create([
            'workspace_id' => $workspaceId,
            'name' => $name,
            'status' => 'draft',
            'nodes' => [],
            'edges' => [],
        ]);
    }

    // ── Listing follows the switched workspace ───────────────────────────────

    #[Test]
    public function the_list_shows_the_home_workspace_when_nothing_is_switched(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->makeAutomation($home->id, 'HomeFlow');
        $this->makeAutomation($other->id, 'OtherFlow');

        $body = $this->actingAs($user)->get(route('client.automations.index'))->assertOk()->getContent();

        $this->assertStringContainsString('HomeFlow', $body);
        $this->assertStringNotContainsString('OtherFlow', $body);
    }

    #[Test]
    public function the_list_follows_the_switched_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->makeAutomation($home->id, 'HomeFlow');
        $this->makeAutomation($other->id, 'OtherFlow');

        $body = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.automations.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('OtherFlow', $body);
        $this->assertStringNotContainsString('HomeFlow', $body);
    }

    #[Test]
    public function a_new_automation_is_created_in_the_switched_workspace(): void
    {
        ['user' => $user, 'other' => $other] = $this->createTwoWorkspaceUser();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->post(route('client.automations.store'), ['name' => 'Created While Switched'])
            ->assertRedirect();

        $this->assertDatabaseHas('automations', [
            'name' => 'Created While Switched',
            'workspace_id' => $other->id,
        ]);
    }

    // ── §G-1b: authorise(), the single gate for six endpoints ───────────────

    #[Test]
    public function editing_another_tenants_automation_is_blocked(): void
    {
        ['user' => $user] = $this->createTwoWorkspaceUser();
        ['home' => $foreign] = $this->createTwoWorkspaceUser(['email' => 'foreign-auto-1@example.com']);
        $automation = $this->makeAutomation($foreign->id, 'ForeignFlow');

        $this->actingAs($user)
            ->get(route('client.automations.edit', $automation))
            ->assertForbidden();
    }

    /** POSITIVE CONTROL for the above. */
    #[Test]
    public function editing_an_automation_in_the_home_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $automation = $this->makeAutomation($home->id, 'MyFlow');

        $this->actingAs($user)
            ->get(route('client.automations.edit', $automation))
            ->assertOk();
    }

    /** §G-1b: after switching, the switched-into workspace's automation is editable. */
    #[Test]
    public function editing_an_automation_in_the_switched_workspace_succeeds(): void
    {
        ['user' => $user, 'other' => $other] = $this->createTwoWorkspaceUser();
        $automation = $this->makeAutomation($other->id, 'OtherFlow');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.automations.edit', $automation))
            ->assertOk();
    }

    /** The converse: once switched away, the home automation is out of scope. */
    #[Test]
    public function after_switching_a_home_workspace_automation_is_out_of_scope(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $automation = $this->makeAutomation($home->id, 'HomeFlow');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.automations.edit', $automation))
            ->assertForbidden();
    }

    #[Test]
    public function deleting_another_tenants_automation_is_blocked(): void
    {
        ['user' => $user] = $this->createTwoWorkspaceUser();
        ['home' => $foreign] = $this->createTwoWorkspaceUser(['email' => 'foreign-auto-2@example.com']);
        $automation = $this->makeAutomation($foreign->id, 'ForeignFlow');

        $this->actingAs($user)
            ->delete(route('client.automations.destroy', $automation))
            ->assertForbidden();

        $this->assertDatabaseHas('automations', ['id' => $automation->id]);
    }

    /** POSITIVE CONTROL. */
    #[Test]
    public function deleting_an_automation_in_the_home_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $automation = $this->makeAutomation($home->id, 'MyFlow');

        $this->actingAs($user)
            ->delete(route('client.automations.destroy', $automation))
            ->assertRedirect();

        $this->assertDatabaseMissing('automations', ['id' => $automation->id]);
    }

    /**
     * generateToken() mints a webhook trigger token. Worth its own case: it is
     * the one gated endpoint that hands back a credential, so authorising it
     * against the wrong workspace would have leaked a trigger token for another
     * tenant's automation.
     */
    #[Test]
    public function generating_a_trigger_token_for_another_tenant_is_blocked(): void
    {
        ['user' => $user] = $this->createTwoWorkspaceUser();
        ['home' => $foreign] = $this->createTwoWorkspaceUser(['email' => 'foreign-auto-3@example.com']);
        $automation = $this->makeAutomation($foreign->id, 'ForeignFlow');

        $this->actingAs($user)
            ->post(route('client.automations.generate-token', $automation))
            ->assertForbidden();

        $this->assertNull($automation->fresh()->trigger_token);
    }

    /** POSITIVE CONTROL. */
    #[Test]
    public function generating_a_trigger_token_in_the_home_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $automation = $this->makeAutomation($home->id, 'MyFlow');

        $this->actingAs($user)
            ->post(route('client.automations.generate-token', $automation))
            ->assertOk()
            ->assertJsonStructure(['trigger_token']);

        $this->assertNotNull($automation->fresh()->trigger_token);
    }
}
