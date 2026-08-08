<?php

namespace Tests\Feature\Workspace;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
     * It is safe only because `admin` is a separate authentication system with
     * its own DB-backed RBAC that no customer can reach.
     */
    #[Test]
    public function the_admin_guard_is_a_deliberate_door_out_of_the_scope(): void
    {
        $this->seedLead(1, 'workspace one');
        $this->seedLead(2, 'workspace two');
        $this->seedLead(3, 'workspace three');

        $admin = $this->createSuperAdmin();
        Auth::guard('admin')->login($admin);

        $this->assertTrue(Auth::guard('admin')->check());

        $this->assertSame(3, ScopedFixture::count(),
            'An authenticated admin must see across workspaces — this is the ruled exception that keeps the admin dashboard correct.');
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
