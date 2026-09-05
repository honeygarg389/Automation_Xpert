<?php

namespace Tests\Feature\SmartQr;

use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Workspace;
use App\Modules\SmartQr\Models\SmartQrAssignment;
use App\Modules\SmartQr\Models\SmartQrBatch;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Models\SmartQrDailyStat;
use App\Modules\SmartQr\Models\SmartQrExport;
use App\Modules\SmartQr\Models\SmartQrScanEvent;
use App\Modules\SmartQr\Support\SmartQrStatus;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Force delete — the local-only batch purge.
 *
 * ─── ⚠️ THIS CLASS BOOTS THE APPLICATION AS `local`, NOT `testing` ──────────
 *
 * The route is registered inside `if (app()->environment('local'))` in the
 * module's route file, which the service provider loads at BOOT. So the
 * codebase's existing trick — `app()->detectEnvironment(fn () => 'local')`
 * inside a test body, as WebhookSignatureTest does — is too late here: it
 * changes what `environment()` reports, but the route table was already built
 * without the route. The environment has to be true before the container boots.
 *
 * Hence the superglobals are set in setUp() BEFORE parent::setUp(), which is
 * what createApplication() reads through Env::get(). They are restored in
 * tearDown so no later test inherits a local app.
 *
 * ⚠️ THIS IS SAFE FOR THE TEST DATABASE, and that was checked rather than
 * assumed: there is no `.env.local` in this repo, so booting as `local` loads
 * the same `.env` as always, and phpunit.xml's DB_DATABASE=whatsmine_test still
 * wins. Both guardrails also remain live — tests/bootstrap.php (pre-framework)
 * and TestCase::setUp()'s resolved-config check — and both abort on any schema
 * whose name does not end in `_test`.
 */
class SmartQrBatchForceDeleteTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        $this->originalEnv = [
            'getenv' => getenv('APP_ENV'),
            'env' => $_ENV['APP_ENV'] ?? null,
            'server' => $_SERVER['APP_ENV'] ?? null,
        ];

        putenv('APP_ENV=local');
        $_ENV['APP_ENV'] = 'local';
        $_SERVER['APP_ENV'] = 'local';

        parent::setUp();

        // The whole point of the class — assert the premise rather than trust it.
        $this->assertSame('local', app()->environment(),
            'These tests are meaningless unless the app actually booted as local.');

        // ⚠️ A CONSEQUENCE OF BOOTING AS `local`, NOT AN UNRELATED CONVENIENCE.
        //
        // VerifyCsrfToken skips itself when Application::runningUnitTests() is
        // true, and that method is literally `$this['env'] === 'testing'`. Boot
        // as anything else and CSRF verification switches on, so every POST and
        // DELETE below 419s. Disabling that ONE middleware restores the normal
        // testing behaviour without touching auth:admin, the permission gate or
        // `demo` — which are the middlewares these tests actually exercise.
        // Laravel 11+ renamed this to ValidateCsrfToken; VerifyCsrfToken still
        // exists as the legacy name, and bootstrap/app.php configures the new
        // one via $middleware->validateCsrfTokens(). Both are disabled so this
        // keeps working whichever the kernel resolves.
        $this->withoutMiddleware([
            ValidateCsrfToken::class,
            VerifyCsrfToken::class,
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (is_string($this->originalEnv['getenv'] ?? null)) {
            putenv('APP_ENV='.$this->originalEnv['getenv']);
        } else {
            putenv('APP_ENV');
        }

        $_ENV['APP_ENV'] = $this->originalEnv['env'];
        $_SERVER['APP_ENV'] = $this->originalEnv['server'];
    }

    /** @param  list<string>  $keys */
    private function admin(array $keys = ['manage_qr_batches']): AdminUser
    {
        $admin = AdminUser::factory()->create();
        $role = Role::firstOrCreate(
            ['key' => 'qr-force-'.md5(implode(',', $keys))],
            ['name' => 'QR Force Test', 'description' => 'test']
        );

        foreach ($keys as $key) {
            $perm = Permission::firstOrCreate(['key' => $key], ['name' => $key, 'category' => 'QR Management']);
            $role->permissions()->syncWithoutDetaching([$perm->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin->fresh();
    }

    /**
     * A batch carrying every kind of row the purge is supposed to destroy:
     * a logo file, codes, a CURRENT and an ENDED assignment, scan events,
     * daily stats, and an export archive that exists on disk.
     *
     * @return array{batch: SmartQrBatch, workspace: Workspace, exportPath: string, logoPath: string}
     */
    private function seedFullBatch(): array
    {
        Storage::fake('local');

        ['workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, Plan::factory()->create(['limits' => ['smart_qr_max_assigned' => 50]]));

        $logoPath = 'branding/force-delete-logo.png';
        Storage::disk('local')->put($logoPath, 'fake-png-bytes');

        $batch = SmartQrBatch::factory()->create([
            'logo_path' => $logoPath,
            'logo_disk' => 'local',
        ]);

        $current = SmartQrCode::factory()->create(['batch_id' => $batch->id, 'serial_number' => 'FD-CURRENT']);
        $ended = SmartQrCode::factory()->create(['batch_id' => $batch->id, 'serial_number' => 'FD-ENDED']);
        SmartQrCode::factory()->create(['batch_id' => $batch->id, 'serial_number' => 'FD-BARE']);

        $currentAssignment = SmartQrAssignment::factory()->create([
            'smart_qr_code_id' => $current->id,
            'workspace_id' => $workspace->id,
            'status' => SmartQrStatus::ASSIGNMENT_ACTIVE,
        ]);

        $endedAssignment = SmartQrAssignment::factory()->create([
            'smart_qr_code_id' => $ended->id,
            'workspace_id' => $workspace->id,
            'status' => SmartQrStatus::ASSIGNMENT_ENDED,
            'unassigned_at' => now()->subDay(),
        ]);

        // Scan events + daily stats hang off assignments and must cascade away.
        foreach ([$currentAssignment, $endedAssignment] as $a) {
            SmartQrScanEvent::create([
                'smart_qr_assignment_id' => $a->id,
                'scanned_at' => now()->subHours(2),
                'is_bot' => false,
                'is_unique' => true,
            ]);

            SmartQrDailyStat::create([
                'smart_qr_assignment_id' => $a->id,
                'stat_date' => now()->subDay()->toDateString(),
                'scans' => 3,
                'unique_scans' => 2,
            ]);
        }

        $exportPath = 'smartqr-exports/force-delete-part-1.zip';
        Storage::disk('local')->put($exportPath, 'fake-zip-bytes');

        SmartQrExport::create([
            'batch_id' => $batch->id,
            'part_number' => 1,
            'total_parts' => 1,
            'format' => 'svg',
            'status' => SmartQrExport::STATUS_READY,
            'path' => $exportPath,
        ]);

        return compact('batch', 'workspace', 'exportPath', 'logoPath');
    }

    #[Test]
    public function force_delete_purges_the_batch_and_every_dependent_row(): void
    {
        ['batch' => $batch, 'exportPath' => $exportPath, 'logoPath' => $logoPath] = $this->seedFullBatch();

        $batchId = $batch->id;
        $codeIds = SmartQrCode::where('batch_id', $batchId)->pluck('id');
        $assignmentIds = SmartQrAssignment::withoutWorkspaceScope('reason: test assertion')
            ->whereIn('smart_qr_code_id', $codeIds)->pluck('id');

        // Precondition: everything actually exists before we assert it is gone.
        $this->assertCount(3, $codeIds);
        $this->assertCount(2, $assignmentIds);
        $this->assertTrue(Storage::disk('local')->exists($exportPath));
        $this->assertTrue(Storage::disk('local')->exists($logoPath));

        $this->actingAs($this->admin(), 'admin')
            ->delete(route('admin.qr.batches.forceDestroy', $batch->uuid))
            ->assertRedirect(route('admin.qr.batches.index'))
            ->assertSessionHasNoErrors();

        // ── the batch and its whole subtree ──────────────────────────────────
        $this->assertDatabaseMissing('smart_qr_batches', ['id' => $batchId]);
        $this->assertSame(0, SmartQrCode::whereIn('id', $codeIds)->count(), 'codes survived');
        $this->assertSame(0, SmartQrAssignment::withoutWorkspaceScope('reason: test assertion')
            ->whereIn('id', $assignmentIds)->count(), 'assignments survived');
        $this->assertSame(0, DB::table('smart_qr_scan_events')
            ->whereIn('smart_qr_assignment_id', $assignmentIds)->count(), 'scan events survived');
        $this->assertSame(0, DB::table('smart_qr_daily_stats')
            ->whereIn('smart_qr_assignment_id', $assignmentIds)->count(), 'daily stats survived');
        $this->assertSame(0, SmartQrExport::where('batch_id', $batchId)->count(), 'export rows survived');

        // ── the files ───────────────────────────────────────────────────────
        $this->assertFalse(Storage::disk('local')->exists($exportPath),
            'The export archive was left orphaned on disk.');
        $this->assertFalse(Storage::disk('local')->exists($logoPath),
            'The batch logo was left orphaned — SmartQrBatch::booted()"s deleting hook did not fire, '
            .'which is what a query-builder delete instead of $batch->delete() would cause.');
    }

    /**
     * ⚠️ The counts are the whole value of this entry. An audit row saying only
     * "a batch was force deleted" cannot answer how much tenant history went
     * with it.
     */
    #[Test]
    public function force_delete_is_audit_logged_with_accurate_counts(): void
    {
        ['batch' => $batch] = $this->seedFullBatch();
        $batchId = $batch->id;
        $number = $batch->batch_number;

        $this->actingAs($this->admin(), 'admin')
            ->delete(route('admin.qr.batches.forceDestroy', $batch->uuid))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'smart_qr.batch_force_deleted',
            'auditable_type' => SmartQrBatch::class,
            'auditable_id' => $batchId,
        ]);

        $meta = DB::table('audit_logs')
            ->where('action', 'smart_qr.batch_force_deleted')
            ->orderByDesc('id')
            ->value('meta');

        $meta = json_decode((string) $meta, true);

        $this->assertSame($number, $meta['batch_number']);
        $this->assertSame(3, $meta['codes_deleted']);
        $this->assertSame(2, $meta['assignments_deleted']);
        $this->assertSame(1, $meta['assignments_current'], 'the current period must be counted');
        $this->assertSame(1, $meta['assignments_ended'], 'the ended period must be counted too');
        $this->assertSame(1, $meta['exports_deleted']);
    }

    /**
     * ⚠️ THE FILESYSTEM MUST NOT BE ABLE TO UNDO A COMMITTED DELETE.
     *
     * By the time the export files are removed the transaction has committed
     * and the rows are gone. Throwing here would report failure for an
     * operation that demonstrably succeeded, so a cleanup fault is recorded and
     * stepped over instead.
     *
     * ⚠️ Storage is mocked to THROW rather than pointed at a missing file,
     * because a missing file is not a failure: Flysystem's local adapter
     * returns true for delete() on a path that is already gone — measured and
     * documented on SmartQrBatch::booted(). A "missing file" test would pass
     * without the try/catch existing at all.
     */
    #[Test]
    public function a_failing_export_cleanup_is_logged_and_does_not_fail_the_purge(): void
    {
        ['batch' => $batch, 'exportPath' => $exportPath] = $this->seedFullBatch();
        $batchId = $batch->id;

        // ⚠️ A REAL THROWING DISK, SWAPPED IN — not a Mockery expectation chain.
        //
        // Mockery's fluent `shouldReceive()->andThrow()` is untypeable
        // (ExpectationInterface|HigherOrderMessage has no andThrow), so it fails
        // PHPStan at level 6, and the documented fixes for that are all
        // suppressions. FilesystemManager::set() takes an untyped disk, so a
        // small object that throws on delete() is both honest and analysable.
        //
        // ⚠️ A MISSING FILE WOULD NOT DO. Flysystem's local adapter returns true
        // for delete() on a path that is already gone, and false — not an
        // exception — for a directory; both were measured. Neither reaches the
        // catch, so a "point it at a bad path" test would pass with the
        // try/catch deleted.
        Storage::set('local', new class
        {
            /** @param  string|array<int, string>|null  $paths */
            public function delete($paths = null): bool
            {
                throw new \RuntimeException('disk exploded');
            }
        });

        $this->actingAs($this->admin(), 'admin')
            ->delete(route('admin.qr.batches.forceDestroy', $batch->uuid))
            ->assertRedirect(route('admin.qr.batches.index'))
            ->assertSessionHasNoErrors();

        // The DB half still completed — a filesystem fault must not roll it back.
        $this->assertDatabaseMissing('smart_qr_batches', ['id' => $batchId]);
        $this->assertSame(0, SmartQrCode::where('batch_id', $batchId)->count());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'smart_qr.export_cleanup_failed',
            'auditable_type' => SmartQrBatch::class,
            'auditable_id' => $batchId,
        ]);

        $meta = json_decode((string) DB::table('audit_logs')
            ->where('action', 'smart_qr.export_cleanup_failed')
            ->orderByDesc('id')->value('meta'), true);

        $this->assertSame($exportPath, $meta['path']);
        $this->assertStringContainsString('disk exploded', $meta['error']);
    }

    #[Test]
    public function force_delete_requires_the_manage_permission(): void
    {
        ['batch' => $batch] = $this->seedFullBatch();

        $this->actingAs($this->admin(['view_qr_inventory']), 'admin')
            ->delete(route('admin.qr.batches.forceDestroy', $batch->uuid))
            ->assertRedirect(route('admin.dashboard'));

        // ⚠️ assertDatabaseHas()'s third argument is the CONNECTION, not a
        // message — a failure message there is silently read as a connection
        // name and blows up with "Database connection [...] not configured".
        $this->assertSame(1, SmartQrBatch::whereKey($batch->id)->count(),
            'A batch was purged by an admin without manage_qr_batches.');
    }

    /** The pages must advertise the control only where it can actually work. */
    #[Test]
    public function the_pages_report_force_delete_as_available_on_local(): void
    {
        ['batch' => $batch] = $this->seedFullBatch();
        $admin = $this->admin(['manage_qr_batches', 'view_qr_inventory']);

        $this->actingAs($admin, 'admin')->get(route('admin.qr.batches.index'))
            ->assertInertia(fn ($p) => $this->assertTrue($p->toArray()['props']['forceDeleteAvailable']));

        $this->actingAs($admin, 'admin')->get(route('admin.qr.batches.show', $batch->uuid))
            ->assertInertia(fn ($p) => $this->assertTrue($p->toArray()['props']['forceDeleteAvailable']));
    }
}
