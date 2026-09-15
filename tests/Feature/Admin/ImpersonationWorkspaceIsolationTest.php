<?php

namespace Tests\Feature\Admin;

use App\Modules\Shared\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * End-to-end proof, through REAL dispatched HTTP requests and REAL routes, for
 * the two things the WorkspaceScope route-based fix (see its class docblock)
 * must not have broken: genuine admin-panel cross-tenant reads, and "Return to
 * Admin" after impersonation ends.
 *
 * `WorkspaceScopeTest` covers the scope's own logic directly, including the
 * exact leak reproduction
 * (`an_admin_impersonating_a_client_does_not_leak_other_workspaces_on_a_client_facing_route`).
 * This file covers the two real user-facing flows built on top of it, dispatched
 * through the actual route/middleware stack rather than a bound fake route.
 */
class ImpersonationWorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The legitimate use case the admin-panel exception exists for. If the fix
     * had overcorrected — e.g. checked something narrower than "behind
     * auth:admin" — this platform-wide total would silently collapse to one
     * workspace's count instead of the sum across all of them.
     */
    #[Test]
    public function a_real_admin_dashboard_request_still_sees_contacts_across_every_workspace(): void
    {
        ['workspace' => $workspaceA] = $this->createWorkspaceContext();
        ['workspace' => $workspaceB] = $this->createWorkspaceContext();

        Contact::factory()->count(2)->create(['workspace_id' => $workspaceA->id]);
        Contact::factory()->count(3)->create(['workspace_id' => $workspaceB->id]);

        $admin = $this->createSuperAdmin();

        $response = $this->actingAs($admin, 'admin')->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Dashboard')
            ->where('stats.contacts_total', 5)
        );
    }

    /**
     * "Return to Admin" is not just a session flag reading true — it must
     * actually land the admin back in a WORKING panel. This dispatches the
     * real impersonate and stop routes end to end and then proves the panel
     * still functions afterwards, not merely that the guard check passes.
     */
    #[Test]
    public function return_to_admin_after_impersonation_lands_back_in_a_working_admin_panel(): void
    {
        ['user' => $clientUser, 'client' => $client] = $this->createWorkspaceContext();

        $admin = $this->createSuperAdmin();
        $this->actingAs($admin, 'admin');

        $impersonateResponse = $this->post(route('admin.clients.impersonate', $client));
        $impersonateResponse->assertRedirect(route('client.dashboard'));

        $this->assertAuthenticatedAs($clientUser, 'web');
        $this->assertTrue(auth('admin')->check(),
            'Admin guard stays authenticated during impersonation by design — see WorkspaceScope\'s class docblock.');

        $stopResponse = $this->post(route('admin.impersonation.stop'));
        $stopResponse->assertRedirect(route('admin.clients.index'));

        $this->assertGuest('web');
        $this->assertTrue(auth('admin')->check(), '"Return to Admin" must not have logged the admin out.');

        // The real proof: the admin can still USE the panel afterwards, not
        // just that the guard flag reads true — a genuine auth:admin route
        // must return 200, not bounce to login.
        $dashboardResponse = $this->get(route('admin.dashboard'));
        $dashboardResponse->assertOk();
    }
}
