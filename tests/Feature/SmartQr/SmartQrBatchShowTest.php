<?php

namespace Tests\Feature\SmartQr;

use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Modules\SmartQr\Models\SmartQrAssignment;
use App\Modules\SmartQr\Models\SmartQrBatch;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Support\SmartQrStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * QrBatchController::show() — assigned_count and active_count.
 *
 * ⚠️ ZERO COVERAGE EXISTED FOR THIS ACTION BEFORE THIS FILE, in either PHP or
 * JS — confirmed by grep across both suites during inspection. This is the
 * first test to render `Admin/SmartQr/Batches/Show` at all.
 *
 * ─── ⚠️ WHY THE FIXTURE SPANS TWO WORKSPACES, AND WHAT IT DOES NOT PROVE ────
 *
 * The two-workspace fixture proves the counts AGGREGATE CORRECTLY ACROSS
 * WORKSPACES rather than accidentally scoping to whichever workspace happens
 * to be first — a real property worth pinning: an implementation that filtered
 * to one workspace by mistake would still pass a single-workspace fixture.
 *
 * ⚠️ IT DOES NOT PROVE THE withoutGlobalScope() CALLS ARE LOAD-BEARING, AND AN
 * EARLIER VERSION OF THIS TEST CLAIMED IT DID. Mutation-tested directly:
 * removing `->withoutGlobalScope(WorkspaceScope::class)` from the controller's
 * `assigned_count` closure left this test GREEN. Root cause —
 * `WorkspaceScope::apply()` returns before attaching any constraint whenever
 * `Auth::guard('admin')->check()` is true, and this action has no route
 * outside the `auth:admin` group, so the scope never filters here regardless
 * of the closure's own unscoping. See the correction in
 * QrBatchController::show()'s comment for the measured detail. What the test
 * below actually verifies is the aggregation and the status filter, which are
 * both real, both mutation-confirmed to fail when broken.
 */
class SmartQrBatchShowTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string> $keys */
    private function adminWith(array $keys): AdminUser
    {
        $admin = AdminUser::factory()->create();
        $role = Role::firstOrCreate(
            ['key' => 'qr-batch-show-test'],
            ['name' => 'QR Batch Show Test Role', 'description' => 'test']
        );

        foreach ($keys as $key) {
            $perm = Permission::firstOrCreate(
                ['key' => $key],
                ['name' => ucwords(str_replace('_', ' ', $key)), 'category' => 'QR Management']
            );
            $role->permissions()->syncWithoutDetaching([$perm->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin->fresh();
    }

    /**
     * ⚠️ THE DISCRIMINATING FIXTURE.
     *
     * Five codes, five distinct assignment states, so a query that gets any
     * ONE of them wrong produces a wrong total rather than an accidentally
     * correct one:
     *
     *   code 1  no assignment at all                        -> neither count
     *   code 2  workspace A, status=inactive, current        -> assigned only
     *   code 3  workspace A, status=active,   current        -> assigned + active
     *   code 4  workspace B, status=active,   current        -> assigned + active
     *   code 5  workspace A, status=active,   ENDED           -> neither count
     *           (a PAST assignment must not count as current — same
     *           "ever vs currently" trap R-4/R-10 exist to prevent)
     *
     * Expected: assigned_count = 3, active_count = 2.
     */
    #[Test]
    public function assigned_and_active_counts_are_correct_across_workspaces(): void
    {
        $admin = $this->adminWith(['view_qr_inventory']);
        ['workspace' => $workspaceA] = $this->createWorkspaceContext();
        ['workspace' => $workspaceB] = $this->createWorkspaceContext();

        $batch = SmartQrBatch::factory()->create();

        $unassigned = SmartQrCode::factory()->create(['batch_id' => $batch->id]);

        $inactiveInA = SmartQrCode::factory()->create(['batch_id' => $batch->id]);
        SmartQrAssignment::factory()->create([
            'smart_qr_code_id' => $inactiveInA->id,
            'workspace_id' => $workspaceA->id,
            'status' => SmartQrStatus::ASSIGNMENT_INACTIVE,
        ]);

        $activeInA = SmartQrCode::factory()->create(['batch_id' => $batch->id]);
        SmartQrAssignment::factory()->create([
            'smart_qr_code_id' => $activeInA->id,
            'workspace_id' => $workspaceA->id,
            'status' => SmartQrStatus::ASSIGNMENT_ACTIVE,
        ]);

        // ⚠️ THE CROSS-WORKSPACE CASE — workspace B, not A.
        $activeInB = SmartQrCode::factory()->create(['batch_id' => $batch->id]);
        SmartQrAssignment::factory()->create([
            'smart_qr_code_id' => $activeInB->id,
            'workspace_id' => $workspaceB->id,
            'status' => SmartQrStatus::ASSIGNMENT_ACTIVE,
        ]);

        // A PAST assignment — ended, so not current. Must not inflate either
        // count, the same "ever vs currently" distinction R-4 draws elsewhere.
        $endedInA = SmartQrCode::factory()->create(['batch_id' => $batch->id]);
        SmartQrAssignment::factory()->ended()->create([
            'smart_qr_code_id' => $endedInA->id,
            'workspace_id' => $workspaceA->id,
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.qr.batches.show', $batch->uuid))
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('batch.assigned_count', 3)
            ->where('batch.active_count', 2)
        );
    }

    /**
     * POSITIVE CONTROL, inverted: a batch with genuinely nothing assigned
     * reports 0/0 — proves the counts are not hardcoded truthy or leaking a
     * count from an unrelated batch/workspace.
     */
    #[Test]
    public function a_batch_with_no_assignments_reports_zero_for_both_counts(): void
    {
        $admin = $this->adminWith(['view_qr_inventory']);
        $batch = SmartQrBatch::factory()->create();
        SmartQrCode::factory()->count(3)->create(['batch_id' => $batch->id]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.qr.batches.show', $batch->uuid))
            ->assertInertia(fn ($page) => $page
                ->where('batch.assigned_count', 0)
                ->where('batch.active_count', 0)
            );
    }

    /**
     * ⚠️ REDIRECT, NOT 403 — measured against RequirePermission::handle().
     *
     * An HTML request that fails the permission check redirects to
     * admin.dashboard with a flash error; only a JSON request gets a 403.
     * assertForbidden() here would fail against the real middleware, not
     * confirm it.
     */
    #[Test]
    public function viewing_a_batch_requires_the_view_permission(): void
    {
        $admin = $this->adminWith([]);
        $batch = SmartQrBatch::factory()->create();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.qr.batches.show', $batch->uuid))
            ->assertRedirect(route('admin.dashboard'));
    }
}
