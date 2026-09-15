<?php

namespace Tests\Feature\Workspace;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0, slice 1. The scope and the trait, wired to NO application model.
 *
 * Introduced the same way `WorkspaceContext` was: proven in isolation first, so
 * the model migration in later slices has something already tested to migrate
 * onto. Applying the trait to 27 models and discovering the mechanism is wrong
 * is the failure this ordering exists to prevent.
 *
 * The subject is `ScopedFixture` below — a model class that exists only in this
 * file. It uses the real `leads` table so no DDL runs inside RefreshDatabase's
 * transaction, but `App\Modules\Leads\Models\Lead` itself is deliberately NOT
 * scoped in this slice; the two classes are independent.
 */
class WorkspaceScopeTest extends TestCase
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

    /** Insert straight to the table — the scope filters reads, so writes are unaffected. */
    private function seedLead(int $workspaceId, string $name): int
    {
        return DB::table('leads')->insertGetId([
            'workspace_id' => $workspaceId,
            'name' => $name,
            'whatsapp_status' => 'unknown',
            'pushed_to_contacts' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Binds a fake matched route carrying exactly the given middleware onto the
     * current request, without a full HTTP dispatch — so `requestIsBehindAdminPanel()`
     * sees the same thing it would after real routing, while the rest of this file
     * stays in its existing style of asserting straight on the model.
     *
     * @param  list<string>  $middleware
     */
    private function bindMatchedRoute(array $middleware): void
    {
        $route = new RoutingRoute(['GET'], '/__test/'.uniqid(), []);
        $route->middleware($middleware);

        request()->setRouteResolver(fn () => $route);
    }

    // ── It filters to the resolved workspace ───────────────────────────────

    #[Test]
    public function the_scope_filters_to_the_resolved_workspace(): void
    {
        $this->seedLead(1, 'workspace one');
        $this->seedLead(2, 'workspace two');
        $this->seedLead(2, 'workspace two again');

        $names = WorkspaceContext::for(2, fn () => ScopedFixture::pluck('name')->all());

        $this->assertSame(['workspace two', 'workspace two again'], $names);
    }

    /**
     * POSITIVE CONTROL. The rows the previous test does not see must genuinely
     * exist — otherwise "returns nothing" would pass for the wrong reason, which
     * is exactly how the five vacuous tests in this codebase passed.
     */
    #[Test]
    public function the_rows_being_filtered_out_really_are_there(): void
    {
        $this->seedLead(1, 'workspace one');
        $this->seedLead(2, 'workspace two');

        $this->assertSame(2, DB::table('leads')->count());

        $seen = WorkspaceContext::for(1, fn () => ScopedFixture::count());

        $this->assertSame(1, $seen, 'The scope must hide the other workspace, not the table.');
    }

    #[Test]
    public function the_scope_qualifies_the_column_with_the_table_name(): void
    {
        // An unqualified `workspace_id` is ambiguous the moment the query joins
        // another table that also has the column — and 29 tables do.
        $sql = WorkspaceContext::for(7, fn () => ScopedFixture::query()->toSql());

        $this->assertStringContainsString('"leads"."workspace_id"', str_replace('`', '"', $sql));
    }

    // ── Null context fails CLOSED ──────────────────────────────────────────

    #[Test]
    public function a_null_workspace_context_matches_nothing(): void
    {
        $this->seedLead(1, 'workspace one');
        $this->seedLead(2, 'workspace two');

        // No authenticated user and no override: the state of a queued job that
        // never established context, an unauthenticated request, or a webhook.
        $this->assertNull(WorkspaceContext::id());

        $this->assertSame(0, ScopedFixture::count(),
            'Null context must fail CLOSED. Returning everything here would make the scope a no-op in exactly the paths that carry the most risk.');
    }

    #[Test]
    public function a_null_workspace_context_matches_nothing_for_a_logged_in_user_with_no_workspace(): void
    {
        $this->seedLead(1, 'workspace one');

        $user = User::factory()->create(['workspace_id' => null]);
        $this->actingAs($user);

        $this->assertNull(WorkspaceContext::id());
        $this->assertSame(0, ScopedFixture::count());
    }

    // ── The admin exception ────────────────────────────────────────────────

    /**
     * ⚠️ THIS IS A DELIBERATE DOOR, NOT AN OVERSIGHT.
     *
     * The admin panel reads across tenants because that is what an admin panel
     * is for — `Admin\DashboardController` counts every contact and every
     * conversation on the platform (hazard H-1 in docs/found-bugs.md). Under a
     * fail-closed scope those counts would silently become 0.
     *
     * It is also the widest hole in the isolation story, which is why it is
     * pinned here by name: anyone auditing this later must be able to see that
     * it was ruled on 2026-08-07, not that someone forgot a guard.
     *
     * ⚠️ UPDATED 2026-09-15 alongside the fix for the impersonation cross-tenant
     * leak (see `WorkspaceScope`'s class docblock and
     * `an_admin_impersonating_a_client_does_not_leak_other_workspaces_on_a_client_facing_route`
     * below). The door used to open on `Auth::guard('admin')->check()` alone,
     * which this test satisfied with nothing more than a guard login — no route
     * involved. That is no longer sufficient to open the door, on purpose: this
     * test now also binds a matched route carrying `auth:admin`, exactly what a
     * real dispatched request to `/admin/*` carries, so it keeps proving the
     * door opens for a genuine admin-panel request rather than for the guard
     * state alone.
     *
     * It is safe only because `admin` is a separate authentication system with
     * its own DB-backed RBAC that no customer can reach, and because the check
     * is against the resolved route rather than a flag a client request could
     * ever influence.
     */
    #[Test]
    public function the_admin_guard_is_a_deliberate_door_out_of_the_scope(): void
    {
        $this->seedLead(1, 'workspace one');
        $this->seedLead(2, 'workspace two');
        $this->seedLead(3, 'workspace three');

        $admin = $this->createSuperAdmin();
        Auth::guard('admin')->login($admin);
        $this->bindMatchedRoute(['web', 'auth:admin', 'demo']);

        $this->assertTrue(Auth::guard('admin')->check());

        $this->assertSame(3, ScopedFixture::count(),
            'An authenticated admin on a genuine admin-panel route must see across workspaces — this is the ruled exception that keeps the admin dashboard correct.');
    }

    /**
     * POSITIVE CONTROL for the door: it must be the ADMIN GUARD that opens it,
     * not merely "somebody is logged in". Without this, the test above is
     * equally consistent with the scope being off for every authenticated user.
     */
    #[Test]
    public function an_ordinary_authenticated_user_does_not_get_the_admin_exception(): void
    {
        // Deliberately far from any auto-increment id: createWorkspaceContext()
        // makes a REAL workspace, and on a fresh database that lands on id 1 —
        // which would collide with a seeded row and make this assertion pass or
        // fail by coincidence rather than by the scope.
        $this->seedLead(9001, 'other tenant');
        $this->seedLead(9002, 'another tenant');

        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $this->actingAs($user);

        $this->assertFalse(Auth::guard('admin')->check());
        $this->assertNotSame(9001, (int) $workspace->id);

        $seen = ScopedFixture::pluck('workspace_id')->unique()->all();

        $this->assertNotContains(9001, $seen);
        $this->assertNotContains(9002, $seen);
        $this->assertSame((int) $workspace->id, WorkspaceContext::id());
    }

    /**
     * ⚠️ THE CONFIRMED LEAK, reproduced exactly. Found 2026-09-15: a Flow
     * created in one workspace was visible in another workspace's Flows page
     * after the admin impersonated a different client, at /app/flows.
     *
     * `Admin\ClientController::impersonate()` logs the `web` guard in as the
     * target client's user but never logs the `admin` guard out —
     * `ImpersonationController::stop()` only logs `web` out too — so BOTH
     * guards are authenticated for the whole impersonation session, which is
     * what makes "Return to Admin" work afterwards. Under the old
     * `Auth::guard('admin')->check()` door, that made every client-facing page
     * visited while impersonating indistinguishable from a genuine admin-panel
     * request: the scope bypassed entirely and returned every workspace's rows.
     *
     * This reproduces the exact dual-guard state and binds a route carrying the
     * real `client-app` group's middleware (see bootstrap/app.php) — a
     * client-facing route, not `/admin/*` — so it fails against the pre-fix
     * code for the same reason the real bug did, and passes only once the door
     * is keyed on the route rather than the guard. Mutation-verified: see the
     * accompanying investigation notes for the revert-and-rerun proof.
     */
    #[Test]
    public function an_admin_impersonating_a_client_does_not_leak_other_workspaces_on_a_client_facing_route(): void
    {
        // Two independent workspaces with their own natural auto-increment ids —
        // not hard-coded, so this cannot pass or fail by coincidence the way the
        // existing 9001/9002 test above guards against.
        ['workspace' => $demoWorkspace] = $this->createWorkspaceContext();
        ['user' => $spaGreenUser, 'workspace' => $spaGreenWorkspace] = $this->createWorkspaceContext();

        $this->seedLead((int) $demoWorkspace->id, 'Demo Client flow A');
        $this->seedLead((int) $demoWorkspace->id, 'Demo Client flow B');
        $this->seedLead((int) $spaGreenWorkspace->id, 'SpaGreen Wellness flow');

        $admin = $this->createSuperAdmin();

        // Exactly Admin\ClientController::impersonate()'s resulting state: both
        // guards authenticated, admin never logged out.
        Auth::guard('admin')->login($admin);
        Auth::guard('web')->login($spaGreenUser);
        WorkspaceContext::flush();

        $this->assertTrue(Auth::guard('admin')->check());
        $this->assertSame((int) $spaGreenWorkspace->id, (int) Auth::guard('web')->user()->workspace_id);
        $this->assertSame((int) $spaGreenWorkspace->id, WorkspaceContext::id(),
            'Context resolution itself was never the bug — it must already be correct here.');

        // A client-facing route: real client-app group middleware, no auth:admin.
        $this->bindMatchedRoute(['web', 'auth', 'verified', 'role:client']);

        $seen = ScopedFixture::pluck('workspace_id')->unique()->all();

        $this->assertSame([(int) $spaGreenWorkspace->id], $seen,
            'Only the impersonated workspace\'s rows may appear on a client-facing route — '.
            "the Demo workspace's rows leaking here is the exact bug: the admin guard staying ".
            'authenticated through impersonation must not open the admin-only door on a '.
            'route that was never the admin panel.');
    }

    // ── Non-HTTP contexts (jobs, commands) are unaffected ──────────────────

    /**
     * CHECK 1, requested during review of the fix above: `requestIsBehindAdminPanel()`
     * calls `request()->route()`, which is NULL outside an HTTP request — a
     * queued job, a console command, a scheduled task (the pattern this
     * session's Restaurant/Petpooja jobs already use via explicit
     * `WorkspaceContext::for()`, never through the router).
     *
     * Confirmed directly (`php artisan tinker --execute='var_dump(request()->route());'`
     * under this exact worktree): `request()` itself is never null — Laravel
     * always binds a default Request even in console mode — but `->route()` is,
     * since no routing ever occurred. `requestIsBehindAdminPanel()`'s
     * `$route !== null && ...` short-circuits on that, so `gatherMiddleware()`
     * is never called on a null route: no crash. And the ambiguous case falls
     * through to normal `WorkspaceContext`-based scoping rather than silently
     * granting the admin exception — the same "ambiguous fails closed/normal,
     * never open" principle the class docblock already states for a null
     * `WorkspaceContext`. No code change was needed; this test exists to prove
     * that rather than assert it from reading.
     */
    #[Test]
    public function a_queued_job_style_invocation_with_no_route_applies_normal_scoping_and_does_not_crash(): void
    {
        $this->seedLead(1, 'workspace one');
        $this->seedLead(2, 'workspace two');

        $this->assertNull(request()->route(),
            'Precondition: this test must genuinely have no matched route, or it proves nothing about the non-HTTP path.');

        $names = WorkspaceContext::for(2, fn () => ScopedFixture::pluck('name')->all());

        $this->assertSame(['workspace two'], $names,
            'A job with no route must still scope normally to the workspace it explicitly established context for.');
    }

    /**
     * Companion to the above: even if an `admin` guard session were somehow
     * live during a no-route invocation (guards are HTTP-session-based and a
     * real queued job would not normally carry one — this is defense in depth,
     * directly analogous to the bug just fixed), the missing route must still
     * refuse the admin exception. Route presence is the gate, not guard state,
     * full stop — this is the same assertion the leak-reproduction test above
     * makes, from the opposite direction (guard present, route absent, instead
     * of route absent, guard present... here: guard present, route STILL absent).
     */
    #[Test]
    public function an_authenticated_admin_guard_with_no_route_still_does_not_get_the_exception(): void
    {
        $this->seedLead(1, 'workspace one');
        $this->seedLead(2, 'workspace two');

        $admin = $this->createSuperAdmin();
        Auth::guard('admin')->login($admin);

        $this->assertTrue(Auth::guard('admin')->check());
        $this->assertNull(request()->route());

        // No workspace context at all (no web-guard user, no override): fails
        // closed, exactly like any other unresolvable context.
        $this->assertSame(0, ScopedFixture::count(),
            'An authenticated admin guard with no matched route must NOT open the admin-only door — route presence is the gate, not guard state.');
    }

    // ── The bypass ─────────────────────────────────────────────────────────

    #[Test]
    public function without_workspace_scope_drops_the_filter_and_requires_a_reason(): void
    {
        $this->seedLead(1, 'workspace one');
        $this->seedLead(2, 'workspace two');

        $all = WorkspaceContext::for(1, fn () => ScopedFixture::withoutWorkspaceScope('reason: test')->count());

        $this->assertSame(2, $all);
    }

    #[Test]
    public function an_empty_reason_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ScopedFixture::withoutWorkspaceScope('   ')->count();
    }

    // ── User must never be scoped ──────────────────────────────────────────

    /**
     * MEASURED, not reasoned about. With the trait applied to `User` and no
     * authenticated context:
     *
     *     User::find($id)                   => null
     *     User::where('email', $e)->first() => null
     *
     * A login lookup happens before anyone is authenticated, so a fail-closed
     * scope on `User` locks every account out of the application.
     *
     * I originally justified this rule by claiming infinite recursion through
     * `WorkspaceContext -> accessibleWorkspaces -> Workspace`. That was wrong —
     * the resolution path completes. The lockout is the real failure and it is
     * worse than the one I predicted, which is the argument for testing the
     * rule rather than documenting the theory.
     */
    #[Test]
    public function the_user_model_is_not_workspace_scoped_and_must_never_be(): void
    {
        $this->assertNotContains(
            BelongsToWorkspace::class,
            class_uses_recursive(User::class),
            'User must NEVER use BelongsToWorkspace. The scope fails closed, and a login '
            .'lookup happens before anyone is authenticated — so User::where(email)->first() '
            .'returns null and every account is locked out. Measured, not theorised.'
        );

        $this->assertArrayNotHasKey(
            WorkspaceScope::class,
            (new User)->getGlobalScopes(),
            'User has acquired a WorkspaceScope by some route other than the trait.'
        );
    }

    #[Test]
    public function the_workspace_model_is_not_workspace_scoped_and_must_never_be(): void
    {
        $this->assertNotContains(
            BelongsToWorkspace::class,
            class_uses_recursive(Workspace::class),
            'Workspace must NEVER use BelongsToWorkspace — the scope resolves THROUGH '
            .'Workspace (WorkspaceContext -> accessibleWorkspaces), so a workspace that can '
            .'only be found from inside a workspace context cannot be found at all.'
        );
    }

    // ── Slice 1 wires the trait to nothing ─────────────────────────────────

}

/**
 * A workspace-owned model that exists only for this test.
 *
 * It borrows the real `leads` table so no DDL runs inside RefreshDatabase's
 * transaction (MySQL would implicitly commit it). It is NOT
 * `App\Modules\Leads\Models\Lead`, which stays unscoped until slice 5.
 */
class ScopedFixture extends Model
{
    use BelongsToWorkspace;

    protected $table = 'leads';

    public $timestamps = true;

    protected $guarded = [];
}
