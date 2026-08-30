<?php

namespace Tests\Feature\SmartQr;

use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Modules\SmartQr\Jobs\GenerateQrExportJob;
use App\Modules\SmartQr\Models\SmartQrBatch;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Models\SmartQrExport;
use App\Modules\SmartQr\Services\SmartQrImageRenderer;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Export archive lifecycle — naming, listing, download, retention.
 *
 * ─── ⚠️ WHAT THIS FILE PINS ─────────────────────────────────────────────────
 *
 *   the decoupling  a truncated DISPLAY list must never decide what may be
 *                   DOWNLOADED — that coupling made files 404 while existing
 *   the naming      Export_{d-M-Y}_{NN}.zip, and the NN never collides
 *   the retention   untracked archives are deleted; TRACKED ones are expired,
 *                   because deleting one by age leaves a `ready` row pointing at
 *                   nothing and the export's own status becomes a lie
 *   the job         the original exception survives the cleanup path, and a job
 *                   that never reaches handle() still marks its row failed
 */
class SmartQrExportCleanupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⚠️ FAKE THE DISK, ALWAYS — and this is not boilerplate.
     *
     * Without it these tests read and WRITE the real storage/app/private tree.
     * RefreshDatabase protects the database; nothing protects the filesystem,
     * so a prune test running against the live disk deletes the developer's
     * actual archives. That happened once here: eight untracked archives were
     * reclaimed by a test run before this line existed.
     *
     * (The tracked-file guard held — every archive with a smart_qr_exports row
     * survived — which is the guard working, not a reason to skip the fake.)
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::factory()->create();
        $role = Role::firstOrCreate(['key' => 'qr-export-cleanup-test'], ['name' => 'T', 'description' => 't']);

        foreach (['view_qr_inventory', 'manage_qr_batches'] as $key) {
            $perm = Permission::firstOrCreate(['key' => $key], ['name' => $key, 'category' => 'QR']);
            $role->permissions()->syncWithoutDetaching([$perm->id]);
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin->fresh();
    }

    /** Writes a fake archive, optionally aged. */
    private function archive(string $name, int $daysOld = 0): string
    {
        $disk = Storage::disk('local');
        $path = GenerateQrExportJob::DIR.'/'.$name;
        $disk->put($path, 'PK-not-a-real-zip');

        if ($daysOld > 0) {
            touch($disk->path($path), now()->subDays($daysOld)->getTimestamp());
        }

        return $path;
    }

    // ══ Part 1 — display cap is not the download allow-list ════════════════

    /**
     * ⚠️ THE REGRESSION. downloadExport() used to validate the requested name
     * against readyExports(), which truncates for display — so an archive past
     * the window 404'd even though the file was sitting right there. With 5 per
     * page and 20 listed, this file is outside EVERY page.
     */
    #[Test]
    public function a_file_outside_the_display_window_is_still_downloadable(): void
    {
        // 25 newer archives push this one past the 20-file listing cap entirely.
        for ($i = 1; $i <= 25; $i++) {
            $this->archive(sprintf('Export_30-Aug-2026_%02d.zip', $i));
        }

        $this->archive('Export_01-Jan-2020_01.zip', daysOld: 400);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.inventory.export-download', 'Export_01-Jan-2020_01.zip'))
            ->assertOk();
    }

    /** POSITIVE CONTROL: the endpoint still refuses what genuinely is not there. */
    #[Test]
    public function a_missing_archive_is_still_a_404(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.inventory.export-download', 'Export_01-Jan-2020_99.zip'))
            ->assertNotFound();
    }

    /** ⚠️ Path traversal and non-zip files stay refused. */
    #[Test]
    public function the_download_endpoint_refuses_traversal_and_non_zip_names(): void
    {
        $admin = $this->admin();
        Storage::disk('local')->put(GenerateQrExportJob::DIR.'/notes.txt', 'x');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.qr.inventory.export-download', 'notes.txt'))
            ->assertNotFound();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.qr.inventory.export-download', '..%2F..%2F.env'))
            ->assertNotFound();
    }

    // ══ Part 2 — Ready Exports pagination ══════════════════════════════════

    #[Test]
    public function the_ready_exports_panel_shows_five_per_page(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->archive(sprintf('Export_30-Aug-2026_%02d.zip', $i));
        }

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.inventory.index'))
            ->assertInertia(fn ($page) => $page
                ->has('readyExports.data', 5)
                ->where('readyExports.current_page', 1)
                ->where('readyExports.last_page', 3)
                ->where('readyExports.total', 12));
    }

    /** ⚠️ 20 is the ceiling on what is LISTED, so 30 files still page to 4. */
    #[Test]
    public function the_listing_is_capped_at_twenty_files_total(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->archive(sprintf('Export_30-Aug-2026_%02d.zip', $i));
        }

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.inventory.index'))
            ->assertInertia(fn ($page) => $page
                ->where('readyExports.total', 20)
                ->where('readyExports.last_page', 4));
    }

    /**
     * ⚠️ Out-of-range pages CLAMP rather than rendering an empty panel, which
     * would read as "no exports" rather than "no such page".
     */
    #[Test]
    public function an_out_of_range_export_page_clamps_to_the_last_page(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->archive(sprintf('Export_30-Aug-2026_%02d.zip', $i));
        }

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.inventory.index', ['export_page' => 999]))
            ->assertInertia(fn ($page) => $page
                ->where('readyExports.current_page', 2)
                ->has('readyExports.data', 2));
    }

    /**
     * ⚠️ Ordering is by the raw timestamp, not the 'Y-m-d H:i' string it used to
     * sort on. Archives written inside one minute tied under the old sort, and
     * at five per page a tie decides what an admin sees.
     */
    #[Test]
    public function the_newest_archives_are_listed_first(): void
    {
        $this->archive('Export_01-Jan-2020_01.zip', daysOld: 10);
        $this->archive('Export_02-Jan-2020_01.zip', daysOld: 5);
        $this->archive('Export_03-Jan-2020_01.zip', daysOld: 1);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.inventory.index'))
            ->assertInertia(fn ($page) => $page
                ->where('readyExports.data.0.name', 'Export_03-Jan-2020_01.zip')
                ->where('readyExports.data.2.name', 'Export_01-Jan-2020_01.zip'));
    }

    // ══ Auto-refresh liveness signal ═══════════════════════════════════════

    /**
     * ⚠️ THE SIGNAL COMES FROM THE QUEUE, because this page has nothing else.
     *
     * The batch detail page polls on batch.status, a column the job moves. An
     * ad-hoc inventory export writes NO smart_qr_exports row — that table's
     * batch_id is NOT NULL and an inventory selection can span batches — so the
     * only evidence an export is building is the job itself.
     */
    #[Test]
    public function an_export_in_flight_is_reported_from_the_queue(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.inventory.index'))
            ->assertInertia(fn ($page) => $page->where('exportInFlight', false));

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Modules\\SmartQr\\Jobs\\GenerateQrExportJob']),
            'attempts' => 0,
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.inventory.index'))
            ->assertInertia(fn ($page) => $page->where('exportInFlight', true));
    }

    /**
     * ⚠️ POSITIVE CONTROL: an unrelated queued job must not make the panel poll.
     * A bare `jobs` count would — the match has to be on the job class.
     */
    #[Test]
    public function an_unrelated_queued_job_does_not_trigger_the_poll(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\SomethingElse']),
            'attempts' => 0,
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.inventory.index'))
            ->assertInertia(fn ($page) => $page->where('exportInFlight', false));
    }

    // ══ Part 3 — inventory page size ═══════════════════════════════════════

    #[Test]
    public function the_inventory_table_paginates_at_twenty_five(): void
    {
        SmartQrCode::factory()->count(30)->create();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.qr.inventory.index'))
            ->assertInertia(fn ($page) => $page
                ->has('codes.data', 25)
                ->where('codes.per_page', 25)
                ->where('codes.total', 30));
    }

    // ══ Part 4 — filename format ═══════════════════════════════════════════

    /**
     * ⚠️ TWO EXPORTS ON ONE DAY MUST NOT COLLIDE. The names are claimed with
     * fopen(x), which fails if the file exists — so a second job takes 02 rather
     * than overwriting 01. A count()+1 scheme would hand both workers the same
     * number and one archive would silently replace the other.
     */
    #[Test]
    public function same_day_exports_get_sequential_numbers(): void
    {
        $batch = SmartQrBatch::create([
            'batch_number' => 'AX-'.Str::upper(Str::random(6)), 'batch_name' => 'B',
            'prefix' => 'AX', 'quantity' => 2, 'serial_start' => 1, 'status' => 'generated',
        ]);
        $codes = SmartQrCode::factory()->count(2)->create(['batch_id' => $batch->id]);

        (new GenerateQrExportJob([$codes[0]->id], 'svg'))->handle(app(SmartQrImageRenderer::class));
        (new GenerateQrExportJob([$codes[1]->id], 'svg'))->handle(app(SmartQrImageRenderer::class));

        $names = collect(Storage::disk('local')->files(GenerateQrExportJob::DIR))
            ->map(fn ($f) => basename($f))->sort()->values();

        $date = now()->format('j-M-Y');

        $this->assertSame(
            ["Export_{$date}_01.zip", "Export_{$date}_02.zip"],
            $names->all(),
            'The second export must claim 02, not overwrite 01.'
        );
    }

    #[Test]
    public function the_filename_matches_the_specified_format(): void
    {
        $code = SmartQrCode::factory()->create();

        (new GenerateQrExportJob([$code->id], 'svg'))->handle(app(SmartQrImageRenderer::class));

        $name = basename(collect(Storage::disk('local')->files(GenerateQrExportJob::DIR))->first());

        $this->assertMatchesRegularExpression('/^Export_\d{1,2}-[A-Z][a-z]{2}-\d{4}_\d{2}\.zip$/', $name);

        // ⚠️ And explicitly NOT the old random-hex scheme, so a revert fails here
        // rather than merely failing the pattern for some other reason.
        $this->assertDoesNotMatchRegularExpression('/^\d{8}-\d{6}-[0-9a-f]{8}\.zip$/', $name);
    }

    /**
     * ⚠️ AN EXISTING ARCHIVE IS NEVER OVERWRITTEN.
     *
     * This is the observable half of the atomic claim. `fopen($path, 'x')` fails
     * when the file exists, so a job whose number is already taken moves on; a
     * plain `fopen($path, 'w')` would truncate the earlier archive and an admin
     * would download one part twice while never receiving the other.
     *
     * ⚠️ WHAT THIS CANNOT PROVE: atomicity under genuine concurrency. Two
     * workers racing between an exists() check and a write cannot be reproduced
     * in a single process, so this pins the CONSEQUENCE (no overwrite) rather
     * than the mechanism. Stated plainly rather than left implied.
     */
    #[Test]
    public function an_existing_archive_is_never_overwritten_by_a_new_export(): void
    {
        $date = now()->format('j-M-Y');
        $taken = $this->archive("Export_{$date}_01.zip");
        Storage::disk('local')->put($taken, 'ORIGINAL-CONTENT');

        $code = SmartQrCode::factory()->create();
        (new GenerateQrExportJob([$code->id], 'svg'))->handle(app(SmartQrImageRenderer::class));

        $this->assertSame('ORIGINAL-CONTENT', Storage::disk('local')->get($taken),
            'The claimed name was reused and the earlier archive was truncated.');
        $this->assertTrue(Storage::disk('local')->exists(GenerateQrExportJob::DIR."/Export_{$date}_02.zip"),
            'The new export should have taken the next free number.');
    }

    /**
     * ⚠️ THE DOUBLE-CLOSE PATH SPECIFICALLY — a failure AFTER the ZIP is closed.
     *
     * The earlier exception test fails during rendering, while the archive is
     * still open, so `close()` in the catch succeeds and the old bug never
     * fires. The defect only appeared when the failure came after close():
     * `@$zip->close()` then threw ValueError from inside the catch, replacing
     * the real error and skipping the tracking below it.
     *
     * Forced here by making the disk write throw, which is the real shape —
     * $disk->put() is the first thing after $zip->close().
     */
    #[Test]
    public function a_failure_after_the_zip_is_closed_still_reports_the_real_reason(): void
    {
        $batch = SmartQrBatch::create([
            'batch_number' => 'AX-'.Str::upper(Str::random(6)), 'batch_name' => 'B',
            'prefix' => 'AX', 'quantity' => 1, 'serial_start' => 1, 'status' => 'generated',
        ]);
        $code = SmartQrCode::factory()->create(['batch_id' => $batch->id]);

        $export = SmartQrExport::create([
            'batch_id' => $batch->id, 'part_number' => 1, 'total_parts' => 1,
            'format' => 'svg', 'status' => SmartQrExport::STATUS_QUEUED,
        ]);

        // ⚠️ A REAL ADAPTER WITH ONE METHOD OVERRIDDEN, not a mock. Every other
        // disk call behaves normally and only put() — the first statement after
        // $zip->close() — fails. That is the exact shape of the defect: the
        // archive is already closed when the failure arrives, so the close() in
        // the catch is the SECOND one, which is what used to throw.
        $fake = Storage::disk('local');

        $failing = new class($fake->getDriver(), $fake->getAdapter(), $fake) extends FilesystemAdapter
        {
            private FilesystemAdapter $inner;

            public function __construct($driver, $adapter, FilesystemAdapter $inner)
            {
                parent::__construct($driver, $adapter);

                $this->inner = $inner;
            }

            /**
             * ⚠️ DELEGATED. FilesystemAdapter builds its path prefixer from the
             * CONFIG array, which this subclass is not given — so without this
             * every path() resolves rootless and the filename claim fails 999
             * times before the code under test is ever reached.
             */
            public function path($path): string
            {
                return $this->inner->path($path);
            }

            public function put($path, $contents, mixed $options = []): string|bool
            {
                throw new \RuntimeException('disk write exploded');
            }
        };

        Storage::set('local', $failing);

        try {
            (new GenerateQrExportJob([$code->id], 'svg', null, $export->id))
                ->handle(app(SmartQrImageRenderer::class));
            $this->fail('The job should have rethrown.');
        } catch (\Throwable $e) {
            $this->assertSame('disk write exploded', $e->getMessage(),
                'The double-close in the catch replaced the real failure with a ValueError.');
        }

        $this->assertSame(SmartQrExport::STATUS_FAILED, $export->fresh()->status,
            'track(FAILED) sits below the cleanup and never ran when close() threw.');
    }

    // ══ Part 6/7 — prune command ═══════════════════════════════════════════

    #[Test]
    public function the_prune_command_refuses_a_shorter_window(): void
    {
        $this->artisan('smartqr:prune-exports --days=1 --force')
            ->expectsOutputToContain('may only keep MORE files, never fewer')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_dry_run_reports_and_deletes_nothing(): void
    {
        $old = $this->archive('Export_01-Jan-2020_01.zip', daysOld: 30);

        $this->artisan('smartqr:prune-exports --dry-run --force')
            ->expectsOutputToContain('nothing was changed')
            ->assertExitCode(0);

        $this->assertTrue(Storage::disk('local')->exists($old), 'A dry run must not delete.');
    }

    #[Test]
    public function untracked_archives_past_retention_are_deleted(): void
    {
        $old = $this->archive('Export_01-Jan-2020_01.zip', daysOld: 30);
        $fresh = $this->archive('Export_30-Aug-2026_01.zip');

        $this->artisan('smartqr:prune-exports --force')->assertExitCode(0);

        $this->assertFalse(Storage::disk('local')->exists($old), 'The stale archive should be gone.');
        $this->assertTrue(Storage::disk('local')->exists($fresh), 'A file inside the window must survive.');
    }

    /**
     * ⚠️ THE GUARD THAT MATTERS. Deleting a tracked archive by age alone leaves a
     * row saying `ready` whose download 404s — the status becomes a lie. The row
     * is EXPIRED and kept instead, because "an export was built" outlives its file.
     */
    #[Test]
    public function tracked_archives_are_expired_rather_than_silently_deleted(): void
    {
        $batch = SmartQrBatch::create([
            'batch_number' => 'AX-'.Str::upper(Str::random(6)), 'batch_name' => 'B',
            'prefix' => 'AX', 'quantity' => 1, 'serial_start' => 1, 'status' => 'generated',
        ]);

        $path = $this->archive('Export_01-Jan-2020_01.zip', daysOld: 30);

        $export = SmartQrExport::create([
            'batch_id' => $batch->id, 'part_number' => 1, 'total_parts' => 1,
            'format' => 'svg', 'status' => SmartQrExport::STATUS_READY, 'path' => $path,
        ]);

        $this->artisan('smartqr:prune-exports --force')->assertExitCode(0);

        $fresh = $export->fresh();

        $this->assertSame(SmartQrExport::STATUS_EXPIRED, $fresh->status, 'The row must survive as expired.');
        $this->assertNull($fresh->path, 'A row must never point at a file that is gone.');
        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertNotNull($fresh, 'The row itself is history and must not be deleted.');
    }

    #[Test]
    public function a_tracked_archive_inside_the_window_is_untouched(): void
    {
        $batch = SmartQrBatch::create([
            'batch_number' => 'AX-'.Str::upper(Str::random(6)), 'batch_name' => 'B',
            'prefix' => 'AX', 'quantity' => 1, 'serial_start' => 1, 'status' => 'generated',
        ]);

        $path = $this->archive('Export_30-Aug-2026_01.zip');

        $export = SmartQrExport::create([
            'batch_id' => $batch->id, 'part_number' => 1, 'total_parts' => 1,
            'format' => 'svg', 'status' => SmartQrExport::STATUS_READY, 'path' => $path,
        ]);

        $this->artisan('smartqr:prune-exports --force')->assertExitCode(0);

        $this->assertSame(SmartQrExport::STATUS_READY, $export->fresh()->status);
        $this->assertTrue(Storage::disk('local')->exists($path));
    }

    // ══ Part 8 — job defects ═══════════════════════════════════════════════

    /**
     * ⚠️ THE ORIGINAL EXCEPTION MUST SURVIVE THE CLEANUP PATH.
     *
     * The catch block called `@$zip->close()` on an already-closed archive.
     * `@` does not suppress exceptions in PHP 8, so close() threw ValueError
     * from inside the catch — replacing the real failure and skipping the
     * Log::error and track(FAILED) below it. A failed export stayed `processing`
     * forever with nothing in the log.
     */
    #[Test]
    public function the_original_exception_survives_the_cleanup_path(): void
    {
        $batch = SmartQrBatch::create([
            'batch_number' => 'AX-'.Str::upper(Str::random(6)), 'batch_name' => 'B',
            'prefix' => 'AX', 'quantity' => 1, 'serial_start' => 1, 'status' => 'generated',
        ]);
        $code = SmartQrCode::factory()->create(['batch_id' => $batch->id]);

        $export = SmartQrExport::create([
            'batch_id' => $batch->id, 'part_number' => 1, 'total_parts' => 1,
            'format' => 'svg', 'status' => SmartQrExport::STATUS_QUEUED,
        ]);

        $renderer = new class extends SmartQrImageRenderer
        {
            public function svg(string $url, string $serial, ?string $logoPath = null): array
            {
                throw new \RuntimeException('renderer exploded');
            }
        };

        try {
            (new GenerateQrExportJob([$code->id], 'svg', null, $export->id))->handle($renderer);
            $this->fail('The job should have rethrown.');
        } catch (\Throwable $e) {
            $this->assertSame('renderer exploded', $e->getMessage(),
                'The ZIP cleanup replaced the real failure with its own.');
        }

        $fresh = $export->fresh();
        $this->assertSame(SmartQrExport::STATUS_FAILED, $fresh->status,
            'track(FAILED) sits below the cleanup and never ran when it threw.');
        $this->assertStringContainsString('renderer exploded', (string) $fresh->error);
    }

    /**
     * ⚠️ handle()'s catch can only run if handle() runs. MaxAttemptsExceeded and
     * the queue timeout are raised by the WORKER, so the row kept whatever status
     * it last held — five such rows existed in development, unmovable.
     */
    #[Test]
    public function the_failed_hook_marks_a_stranded_row(): void
    {
        $batch = SmartQrBatch::create([
            'batch_number' => 'AX-'.Str::upper(Str::random(6)), 'batch_name' => 'B',
            'prefix' => 'AX', 'quantity' => 1, 'serial_start' => 1, 'status' => 'generated',
        ]);

        $export = SmartQrExport::create([
            'batch_id' => $batch->id, 'part_number' => 1, 'total_parts' => 1,
            'format' => 'svg', 'status' => SmartQrExport::STATUS_QUEUED,
        ]);

        (new GenerateQrExportJob([1], 'svg', null, $export->id))
            ->failed(new \RuntimeException('Attempted too many times.'));

        $fresh = $export->fresh();
        $this->assertSame(SmartQrExport::STATUS_FAILED, $fresh->status);
        $this->assertStringContainsString('Attempted too many times', (string) $fresh->error);
    }

    /** ⚠️ Must not overwrite a specific reason already recorded by handle(). */
    #[Test]
    public function the_failed_hook_leaves_an_already_failed_row_alone(): void
    {
        $batch = SmartQrBatch::create([
            'batch_number' => 'AX-'.Str::upper(Str::random(6)), 'batch_name' => 'B',
            'prefix' => 'AX', 'quantity' => 1, 'serial_start' => 1, 'status' => 'generated',
        ]);

        $export = SmartQrExport::create([
            'batch_id' => $batch->id, 'part_number' => 1, 'total_parts' => 1,
            'format' => 'svg', 'status' => SmartQrExport::STATUS_FAILED, 'error' => 'the specific reason',
        ]);

        (new GenerateQrExportJob([1], 'svg', null, $export->id))
            ->failed(new \RuntimeException('generic worker failure'));

        $this->assertSame('the specific reason', $export->fresh()->error);
    }
}
