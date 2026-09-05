<?php

namespace Tests\Feature\SmartQr;

use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Modules\SmartQr\Http\Controllers\Admin\QrBatchController;
use App\Modules\SmartQr\Models\SmartQrAssignment;
use App\Modules\SmartQr\Models\SmartQrBatch;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Support\SmartQrStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * ═══ THE TEST THAT PROTECTS PRODUCTION ══════════════════════════════════════
 *
 * A SEPARATE CLASS from SmartQrBatchForceDeleteTest, and deliberately so: that
 * one boots the app as `local` in setUp() to make the route exist at all. This
 * one runs in the ORDINARY `testing` environment, exactly as every other test
 * in the suite does, so what it asserts is what staging and production get.
 *
 * Splitting them is the whole point. Sharing one class would mean one setUp()
 * deciding the environment per test method, and a future edit to that method
 * could flip this test into `local` without anyone noticing it had stopped
 * testing anything.
 *
 * Two independent guards are checked separately, because either alone would be
 * enough to make the OTHER look unnecessary:
 *
 *   the ROUTE       registered inside `if (app()->environment('local'))`, so
 *                   off local the URL resolves to nothing at all
 *   the CONTROLLER  abort_unless(app()->environment('local'), 404) as the first
 *                   statement, covering any future path that reaches the method
 *                   without going through that route block
 */
class SmartQrBatchForceDeleteBlockedTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): AdminUser
    {
        $admin = AdminUser::factory()->create();
        $role = Role::firstOrCreate(
            ['key' => 'qr-force-blocked'],
            ['name' => 'QR Force Blocked Test', 'description' => 'test']
        );

        // ⚠️ BOTH keys. manage_qr_batches is what force-delete would need;
        // view_qr_inventory is what the batches index/show pages gate on, and
        // without it those routes redirect to the dashboard and never render an
        // Inertia response for the prop assertions to read.
        foreach (['manage_qr_batches', 'view_qr_inventory'] as $key) {
            $perm = Permission::firstOrCreate(['key' => $key], ['name' => $key, 'category' => 'QR Management']);
            $role->permissions()->syncWithoutDetaching([$perm->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin->fresh();
    }

    /**
     * ⚠️ NOT named seed() — that collides with Laravel's own public
     * TestCase::seed() helper and PHP fatals on the access-level mismatch
     * before a single test runs.
     *
     * @return array{batch: SmartQrBatch, code: SmartQrCode, assignment: SmartQrAssignment}
     */
    private function seedBatch(): array
    {
        ['workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, Plan::factory()->create(['limits' => ['smart_qr_max_assigned' => 50]]));

        $batch = SmartQrBatch::factory()->create();
        $code = SmartQrCode::factory()->create(['batch_id' => $batch->id, 'serial_number' => 'BLOCKED-01']);
        $assignment = SmartQrAssignment::factory()->create([
            'smart_qr_code_id' => $code->id,
            'workspace_id' => $workspace->id,
            'status' => SmartQrStatus::ASSIGNMENT_ACTIVE,
        ]);

        return compact('batch', 'code', 'assignment');
    }

    /** Precondition for everything below: this run is NOT local. */
    #[Test]
    public function the_suite_runs_outside_local(): void
    {
        $this->assertNotSame('local', app()->environment(),
            'The blocked-path assertions below prove nothing if the app booted as local.');
        $this->assertSame('testing', app()->environment());
    }

    /**
     * ⚠️ ASSERTED ON THE ROUTE TABLE, not by making a request. A request would
     * 404 whether the route is absent OR present-but-aborting, so it cannot
     * tell the two guards apart. This one names the route table directly.
     */
    #[Test]
    public function the_force_delete_route_is_not_registered_outside_local(): void
    {
        $this->assertFalse(
            app('router')->getRoutes()->hasNamedRoute('admin.qr.batches.forceDestroy'),
            'The force-delete route exists outside local — the environment guard in '
            .'routes/admin.php is not doing its job.'
        );

        // The sibling routes ARE registered, so the assertion above is about
        // this one route and not about the whole file failing to load.
        $this->assertTrue(app('router')->getRoutes()->hasNamedRoute('admin.qr.batches.destroy'));
        $this->assertTrue(app('router')->getRoutes()->hasNamedRoute('admin.qr.batches.retire'));
    }

    /**
     * ⚠️ THE URL IS BUILT BY HAND. route() cannot name a route that does not
     * exist, so asking the router for it would throw before any request is
     * made — and a test that dies constructing its own URL proves nothing about
     * what a prober would receive.
     */
    #[Test]
    public function hitting_the_force_delete_url_outside_local_404s_and_deletes_nothing(): void
    {
        ['batch' => $batch, 'code' => $code, 'assignment' => $assignment] = $this->seedBatch();

        $this->actingAs($this->admin(), 'admin')
            ->delete("/admin/qr/batches/{$batch->uuid}/force")
            ->assertNotFound();

        $this->assertSame(1, SmartQrBatch::whereKey($batch->id)->count(), 'the batch was deleted');
        $this->assertSame(1, SmartQrCode::whereKey($code->id)->count(), 'the code was deleted');
        $this->assertSame(1, SmartQrAssignment::withoutWorkspaceScope('reason: test assertion')
            ->whereKey($assignment->id)->count(), 'the assignment was deleted');

        $this->assertDatabaseMissing('audit_logs', ['action' => 'smart_qr.batch_force_deleted']);
    }

    /**
     * ⚠️ THE CONTROLLER'S OWN GUARD, REACHED WITHOUT THE ROUTE.
     *
     * The test above cannot distinguish "no route" from "route aborted", so it
     * cannot prove the abort_unless() line does anything. This calls the method
     * directly, which is the only way to exercise the second guard while the
     * first one is what is stopping traffic — and it is the guard that would
     * still stand if someone moved the route out of the environment block.
     */
    #[Test]
    public function the_controller_aborts_with_404_even_when_called_directly(): void
    {
        ['batch' => $batch] = $this->seedBatch();

        $controller = app(QrBatchController::class);

        try {
            $controller->forceDestroy(request(), $batch);
            $this->fail('forceDestroy() ran outside local — abort_unless() did not fire.');
        } catch (NotFoundHttpException $e) {
            $this->assertSame(404, $e->getStatusCode(),
                'The refusal must be 404, not 403 — a 403 confirms the endpoint exists.');
        }

        $this->assertSame(1, SmartQrBatch::whereKey($batch->id)->count(),
            'The batch was destroyed despite the abort.');
    }

    /** The UI must not offer a control that would 404. */
    #[Test]
    public function the_pages_report_force_delete_as_unavailable_outside_local(): void
    {
        ['batch' => $batch] = $this->seedBatch();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get(route('admin.qr.batches.index'))
            ->assertInertia(fn ($p) => $this->assertFalse($p->toArray()['props']['forceDeleteAvailable']));

        $this->actingAs($admin, 'admin')->get(route('admin.qr.batches.show', $batch->uuid))
            ->assertInertia(fn ($p) => $this->assertFalse($p->toArray()['props']['forceDeleteAvailable']));
    }
}
