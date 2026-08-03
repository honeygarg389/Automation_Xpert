<?php

namespace Tests\Feature\Workspace\Modules;

use App\Modules\Leads\Models\Lead;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 1c — Leads module (1 resolution site).
 *
 * LeadController::workspaceId() feeds three list queries AND the abort_unless()
 * in destroy(), so it is a §G-1b authorization site: before 1c that check was
 * evaluated against the user's HOME workspace regardless of which workspace they
 * had switched into.
 *
 * Every assertion of "blocked" is paired with a positive control, per CLAUDE.md.
 */
class LeadsWorkspaceScopingTest extends TestCase
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

    private function lead(int $workspaceId, string $name): Lead
    {
        return Lead::factory()->create([
            'workspace_id' => $workspaceId,
            'name' => $name,
        ]);
    }

    // ── Listing follows the switched workspace ───────────────────────────────

    #[Test]
    public function the_list_shows_the_home_workspace_when_nothing_is_switched(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->lead($home->id, 'HomeLead');
        $this->lead($other->id, 'OtherLead');

        $body = $this->actingAs($user)->get(route('client.leads.index'))->assertOk()->getContent();

        $this->assertStringContainsString('HomeLead', $body);
        $this->assertStringNotContainsString('OtherLead', $body);
    }

    /**
     * The behaviour 1c exists to deliver: switching workspace changes what the
     * module returns. Before 1c this assertion failed — the list stayed on the
     * home workspace no matter what the session said.
     */
    #[Test]
    public function the_list_follows_the_switched_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->lead($home->id, 'HomeLead');
        $this->lead($other->id, 'OtherLead');

        $body = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.leads.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('OtherLead', $body, 'Switching must change what the list returns.');
        $this->assertStringNotContainsString('HomeLead', $body, 'The home workspace must no longer leak in.');
    }

    // ── §G-1b: destroy()'s abort_unless, re-verified with a working switcher ──

    #[Test]
    public function deleting_a_lead_from_another_tenant_is_blocked(): void
    {
        ['user' => $user] = $this->createTwoWorkspaceUser();
        ['home' => $foreignWorkspace] = $this->createTwoWorkspaceUser(['email' => 'foreign-leads@example.com']);

        $foreignLead = $this->lead($foreignWorkspace->id, 'ForeignLead');

        $this->actingAs($user)
            ->delete(route('client.leads.destroy', $foreignLead))
            ->assertForbidden();

        $this->assertDatabaseHas('leads', ['id' => $foreignLead->id]);
    }

    /**
     * POSITIVE CONTROL. A 403 above proves nothing on its own — it is equally
     * consistent with destroy() rejecting everyone.
     */
    #[Test]
    public function deleting_a_lead_in_the_home_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $lead = $this->lead($home->id, 'OwnLead');

        $this->actingAs($user)
            ->delete(route('client.leads.destroy', $lead))
            ->assertRedirect();

        $this->assertDatabaseMissing('leads', ['id' => $lead->id]);
    }

    /**
     * The G-1b case specifically: after switching, authorization must be
     * evaluated against the ACTIVE workspace. Before 1c this failed — the check
     * compared against the home workspace, so deleting a lead belonging to the
     * switched-into workspace was refused.
     */
    #[Test]
    public function deleting_a_lead_in_the_switched_workspace_succeeds(): void
    {
        ['user' => $user, 'other' => $other] = $this->createTwoWorkspaceUser();
        $lead = $this->lead($other->id, 'SwitchedLead');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->delete(route('client.leads.destroy', $lead))
            ->assertRedirect();

        $this->assertDatabaseMissing('leads', ['id' => $lead->id]);
    }

    /**
     * And the converse: once switched, a lead in the user's OWN home workspace
     * is no longer in scope. This is the behaviour change users will notice —
     * correct, but a change.
     */
    #[Test]
    public function after_switching_a_home_workspace_lead_is_out_of_scope(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeLead = $this->lead($home->id, 'HomeLead');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->delete(route('client.leads.destroy', $homeLead))
            ->assertForbidden();

        $this->assertDatabaseHas('leads', ['id' => $homeLead->id]);
    }
}
