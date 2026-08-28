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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * Batch-scoped, chunked export — the new path.
 *
 * ⚠️ SEPARATE FROM THE INVENTORY BULK EXPORT, WHICH IS UNCHANGED. That flow
 * (QrInventoryController::export -> GenerateQrExportJob with no tracking row,
 * discovered by directory listing) keeps its own tests in
 * SmartQrImageExportTest and SmartQrAdminInventoryTest, and this slice
 * deliberately modified neither. The last test in this file asserts that
 * separation directly rather than trusting it.
 */
class SmartQrBatchExportTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string> $keys */
    private function adminWith(array $keys): AdminUser
    {
        $admin = AdminUser::factory()->create();
        $role = Role::firstOrCreate(
            ['key' => 'qr-batch-export-test'],
            ['name' => 'QR Batch Export Test Role', 'description' => 'test']
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
     * ⚠️ Codes are created with a raw insert, not the factory.
     *
     * 1,500 factory calls is 1,500 round trips and makes the chunk-math tests
     * take minutes. serial_number and public_token are both UNIQUE, so they are
     * generated deterministically per-index rather than randomly — a collision
     * here would fail the test for a reason that has nothing to do with
     * chunking.
     */
    private function batchWithCodes(int $count): SmartQrBatch
    {
        $batch = SmartQrBatch::factory()->create(['quantity' => $count]);

        $now = now();
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'serial_number' => sprintf('%s-%06d', $batch->prefix, $i),
                'public_token' => sprintf('tok-%d-%06d', $batch->id, $i),
                'batch_id' => $batch->id,
                'status' => 'generated',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            SmartQrCode::insert($chunk);
        }

        return $batch;
    }

    // ══ CHUNK MATH ═════════════════════════════════════════════════════════

    /**
     * ⚠️ THE BOUNDARY THAT MATTERS IS 500 vs 501, not "does it divide".
     *
     * An off-by-one in array_chunk's ceiling shows up as a 500-code batch
     * producing two parts (one of them empty) or a 501-code batch producing
     * one — both of which look plausible in a summary and are wrong. Each case
     * asserts the PART COUNT and the per-part id counts, because a correct
     * total with a wrong split is still a broken export.
     */
    #[Test]
    public function a_500_code_batch_produces_exactly_one_part(): void
    {
        Queue::fake();
        $batch = $this->batchWithCodes(500);

        $this->actingAs($this->adminWith(['view_qr_inventory']), 'admin')
            ->post(route('admin.qr.batches.export', $batch->uuid))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, SmartQrExport::where('batch_id', $batch->id)->count());
        $this->assertSame([1], SmartQrExport::where('batch_id', $batch->id)->pluck('part_number')->all());
        $this->assertSame([1], SmartQrExport::where('batch_id', $batch->id)->pluck('total_parts')->unique()->all());

        Queue::assertPushed(GenerateQrExportJob::class, 1);
        Queue::assertPushed(fn (GenerateQrExportJob $j) => count($j->codeIds) === 500);
    }

    #[Test]
    public function a_501_code_batch_produces_two_parts_of_500_and_1(): void
    {
        Queue::fake();
        $batch = $this->batchWithCodes(501);

        $this->actingAs($this->adminWith(['view_qr_inventory']), 'admin')
            ->post(route('admin.qr.batches.export', $batch->uuid))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, SmartQrExport::where('batch_id', $batch->id)->count());
        $this->assertSame([1, 2], SmartQrExport::forBatch($batch->id)->pluck('part_number')->all());
        $this->assertSame([2], SmartQrExport::where('batch_id', $batch->id)->pluck('total_parts')->unique()->all());

        Queue::assertPushed(GenerateQrExportJob::class, 2);
        Queue::assertPushed(fn (GenerateQrExportJob $j) => count($j->codeIds) === 500);
        Queue::assertPushed(fn (GenerateQrExportJob $j) => count($j->codeIds) === 1);
    }

    #[Test]
    public function a_1500_code_batch_produces_three_full_parts(): void
    {
        Queue::fake();
        $batch = $this->batchWithCodes(1500);

        $this->actingAs($this->adminWith(['view_qr_inventory']), 'admin')
            ->post(route('admin.qr.batches.export', $batch->uuid))
            ->assertSessionHasNoErrors();

        $this->assertSame(3, SmartQrExport::where('batch_id', $batch->id)->count());
        $this->assertSame([1, 2, 3], SmartQrExport::forBatch($batch->id)->pluck('part_number')->all());
        $this->assertSame([3], SmartQrExport::where('batch_id', $batch->id)->pluck('total_parts')->unique()->all());

        Queue::assertPushed(GenerateQrExportJob::class, 3);

        // ⚠️ EVERY id appears exactly once across the parts. A chunker that
        // overlaps or drops a window still produces the right PART count.
        $seen = [];
        Queue::assertPushed(function (GenerateQrExportJob $j) use (&$seen) {
            $seen = array_merge($seen, $j->codeIds);

            return true;
        });
        $this->assertCount(1500, $seen);
        $this->assertCount(1500, array_unique($seen), 'A code id was exported in more than one part.');
    }

    /**
     * The `quantity` ceiling (StoreQrBatchRequest caps at 10,000) is exactly
     * 20 parts. Asserted as arithmetic rather than by inserting 10,000 rows,
     * which would dominate the suite's runtime for a property array_chunk
     * already guarantees at the smaller sizes above.
     */
    #[Test]
    public function the_ten_thousand_ceiling_is_twenty_parts(): void
    {
        $this->assertSame(20, (int) ceil(10000 / GenerateQrExportJob::MAX_CODES));
        $this->assertSame(500, GenerateQrExportJob::MAX_CODES,
            'The cap moved. The 20-part ceiling and every chunk assertion above assume 500.');
    }

    // ══ INPUT CONTRACT ═════════════════════════════════════════════════════

    /**
     * ⚠️ code_ids FROM THE REQUEST MUST BE IGNORED ENTIRELY.
     *
     * The batch's rows are the source of truth. If the controller ever read a
     * client-supplied list, an admin could export codes from another batch —
     * or a subset — through a route whose whole contract is "this batch". The
     * discriminator is that posting a foreign id changes NOTHING about what is
     * dispatched.
     */
    #[Test]
    public function code_ids_supplied_by_the_client_are_ignored(): void
    {
        Queue::fake();
        $batch = $this->batchWithCodes(3);
        $foreign = SmartQrCode::factory()->create();

        $this->actingAs($this->adminWith(['view_qr_inventory']), 'admin')
            ->post(route('admin.qr.batches.export', $batch->uuid), [
                'code_ids' => [$foreign->id],
            ])
            ->assertSessionHasNoErrors();

        $ownIds = SmartQrCode::where('batch_id', $batch->id)->pluck('id')->all();

        Queue::assertPushed(function (GenerateQrExportJob $j) use ($ownIds, $foreign) {
            return $j->codeIds === $ownIds
                && ! in_array($foreign->id, $j->codeIds, true);
        });
    }

    #[Test]
    public function an_invalid_format_is_refused(): void
    {
        Queue::fake();
        $batch = $this->batchWithCodes(2);

        $this->actingAs($this->adminWith(['view_qr_inventory']), 'admin')
            ->post(route('admin.qr.batches.export', $batch->uuid), ['format' => 'tiff'])
            ->assertSessionHasErrors('format');

        Queue::assertNothingPushed();
        $this->assertSame(0, SmartQrExport::count(), 'A tracking row survived a refused request.');
    }

    #[Test]
    public function a_batch_with_no_codes_yet_is_refused_without_creating_rows(): void
    {
        Queue::fake();
        $batch = SmartQrBatch::factory()->create();

        $this->actingAs($this->adminWith(['view_qr_inventory']), 'admin')
            ->from(route('admin.qr.batches.show', $batch->uuid))
            ->post(route('admin.qr.batches.export', $batch->uuid))
            ->assertSessionHasErrors('export');

        Queue::assertNothingPushed();
        $this->assertSame(0, SmartQrExport::count());
    }

    #[Test]
    public function exporting_requires_the_view_permission(): void
    {
        Queue::fake();
        $batch = $this->batchWithCodes(2);

        // HTML request -> RequirePermission redirects to the dashboard rather
        // than returning 403. Measured, not assumed.
        $this->actingAs($this->adminWith([]), 'admin')
            ->post(route('admin.qr.batches.export', $batch->uuid))
            ->assertRedirect(route('admin.dashboard'));

        Queue::assertNothingPushed();
    }

    // ══ TRACKING ROW LIFECYCLE ═════════════════════════════════════════════

    /**
     * queued -> processing -> ready, with the path recorded.
     *
     * Runs the real job (not Queue::fake) so the status transitions are
     * observed rather than assumed — asserting the STORED ROW, not the
     * dispatched payload.
     */
    #[Test]
    public function a_tracked_export_goes_queued_then_ready_with_its_path(): void
    {
        Storage::fake('local');
        $batch = $this->batchWithCodes(2);
        $codeIds = SmartQrCode::where('batch_id', $batch->id)->pluck('id')->all();

        $export = SmartQrExport::create([
            'batch_id' => $batch->id,
            'part_number' => 1,
            'total_parts' => 1,
            'format' => 'svg',
            'status' => SmartQrExport::STATUS_QUEUED,
        ]);

        $this->assertSame(SmartQrExport::STATUS_QUEUED, $export->status);
        $this->assertNull($export->path);

        (new GenerateQrExportJob($codeIds, 'svg', null, $export->id))
            ->handle(app(SmartQrImageRenderer::class));

        $export->refresh();
        $this->assertSame(SmartQrExport::STATUS_READY, $export->status);
        $this->assertNotNull($export->path, 'A ready export recorded no path to its archive.');
        $this->assertTrue(Storage::disk('local')->exists($export->path),
            'The row names an archive that is not on disk.');
        $this->assertNull($export->error);
    }

    /**
     * ⚠️ queued -> failed, WITH the reason captured on the row.
     *
     * The log line alone is not enough: the admin looking at the batch's export
     * panel never sees logs. Slice 2's lesson — a failure reason written where
     * it cannot be read leaves a stuck job and no explanation.
     */
    #[Test]
    public function a_failed_tracked_export_records_the_reason_on_the_row(): void
    {
        Storage::fake('local');
        $batch = $this->batchWithCodes(2);
        $codeIds = SmartQrCode::where('batch_id', $batch->id)->pluck('id')->all();

        $export = SmartQrExport::create([
            'batch_id' => $batch->id,
            'part_number' => 1,
            'total_parts' => 1,
            'format' => 'svg',
            'status' => SmartQrExport::STATUS_QUEUED,
        ]);

        $this->app->bind(SmartQrImageRenderer::class, fn () => new class extends SmartQrImageRenderer
        {
            public function svg(string $url, string $serial, ?string $logoPath = null): array
            {
                throw new \RuntimeException('render exploded');
            }
        });

        try {
            (new GenerateQrExportJob($codeIds, 'svg', null, $export->id))
                ->handle(app(SmartQrImageRenderer::class));
            $this->fail('The job swallowed a render failure and reported success.');
        } catch (\RuntimeException) {
            // expected — the queue must still see the job fail
        }

        $export->refresh();
        $this->assertSame(SmartQrExport::STATUS_FAILED, $export->status);
        $this->assertSame('render exploded', $export->error,
            'The failure reason was not recorded where the admin can read it.');
        $this->assertNull($export->path, 'A failed export named an archive that was never written.');
    }

    // ══ READ ENDPOINT ══════════════════════════════════════════════════════

    /**
     * ⚠️ UNCAPPED AND BATCH-SCOPED — the two properties the old filename-listing
     * approach could not provide. readyExports() takes the 20 most recent files
     * from a shared directory; 21 parts here must all be returned, and another
     * batch's parts must not leak in.
     */
    #[Test]
    public function the_read_endpoint_returns_all_parts_for_only_that_batch(): void
    {
        $batch = SmartQrBatch::factory()->create();
        $other = SmartQrBatch::factory()->create();

        for ($i = 1; $i <= 21; $i++) {
            SmartQrExport::create([
                'batch_id' => $batch->id, 'part_number' => $i, 'total_parts' => 21,
                'format' => 'svg', 'status' => SmartQrExport::STATUS_QUEUED,
            ]);
        }
        SmartQrExport::create([
            'batch_id' => $other->id, 'part_number' => 1, 'total_parts' => 1,
            'format' => 'svg', 'status' => SmartQrExport::STATUS_READY,
        ]);

        $response = $this->actingAs($this->adminWith(['view_qr_inventory']), 'admin')
            ->getJson(route('admin.qr.batches.exports', $batch->uuid))
            ->assertOk();

        $parts = $response->json('exports');
        $this->assertCount(21, $parts, 'The read endpoint capped or dropped parts.');
        $this->assertSame(range(1, 21), array_column($parts, 'part_number'),
            'Parts came back out of order.');
        foreach ($parts as $p) {
            $this->assertSame(21, $p['total_parts']);
        }
    }

    // ══ DOWNLOADING A PART ═════════════════════════════════════════════════

    /**
     * ⚠️ ITS OWN ROUTE, NOT inventory.export-download — AND THIS IS THE TEST
     * THAT PROVES WHY.
     *
     * That route validates the filename against readyExports(), which lists the
     * export directory and takes the 20 MOST RECENT. Batch parts are written to
     * that same directory, so a 20-part export plus any other archive pushes
     * the earliest parts out of the window — 404 for a file that exists and
     * that the admin was just told was ready. Keyed on the row instead, the
     * listing (and its cap) is not consulted at all.
     */
    #[Test]
    public function a_ready_part_downloads_by_its_row_regardless_of_the_directory_listing(): void
    {
        Storage::fake('local');
        $batch = SmartQrBatch::factory()->create(['batch_number' => 'AX-BK-DL']);

        // ⚠️ 25 OTHER archives, so this part is far outside readyExports()'s
        // 20-item window. If the download consulted that listing, this 404s.
        for ($i = 1; $i <= 25; $i++) {
            Storage::disk('local')->put("smartqr-exports/other-{$i}.zip", 'x');
        }

        Storage::disk('local')->put('smartqr-exports/mine.zip', 'zip-bytes');
        $export = SmartQrExport::create([
            'batch_id' => $batch->id, 'part_number' => 3, 'total_parts' => 20,
            'format' => 'svg', 'status' => SmartQrExport::STATUS_READY,
            'path' => 'smartqr-exports/mine.zip',
        ]);

        $response = $this->actingAs($this->adminWith(['view_qr_inventory']), 'admin')
            ->get(route('admin.qr.batches.export-download', [$batch->uuid, $export->id]))
            ->assertOk();

        // ⚠️ .zip, WITH the format as a NAME SEGMENT — not as the extension.
        // The first version of this assertion expected `...-of20.svg`, which
        // matched the formula the code used at the time and therefore CONFIRMED
        // the bug instead of catching it: the served file is a ZIP, so a .svg
        // name produced a download that would not open.
        $response->assertDownload('AX-BK-DL-part3-of20-svg.zip');
    }

    /**
     * ⚠️ THE EXTENSION MUST MATCH THE BYTES, NOT A FORMULA.
     *
     * The filename assertion above can only ever agree with whatever sprintf()
     * the controller happens to use — if both change together it stays green
     * while serving an unopenable file, which is exactly what happened. This
     * asserts the two independently: the name ends in .zip AND the body starts
     * with the ZIP local-file-header magic. A mismatch fails here even if the
     * formula and the assertion are edited in lockstep.
     */
    #[Test]
    public function the_served_filename_extension_matches_the_actual_bytes(): void
    {
        Storage::fake('local');
        $batch = SmartQrBatch::factory()->create(['batch_number' => 'AX-BK-MAGIC']);

        // A real ZIP, built the way the job builds one — not a stub, so the
        // magic bytes are genuine rather than hand-written.
        $tmp = tempnam(sys_get_temp_dir(), 'zt');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('T-000001.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $zip->close();
        Storage::disk('local')->put('smartqr-exports/real.zip', file_get_contents($tmp));
        @unlink($tmp);

        $export = SmartQrExport::create([
            'batch_id' => $batch->id, 'part_number' => 1, 'total_parts' => 1,
            'format' => 'png', 'status' => SmartQrExport::STATUS_READY,
            'path' => 'smartqr-exports/real.zip',
        ]);

        $response = $this->actingAs($this->adminWith(['view_qr_inventory']), 'admin')
            ->get(route('admin.qr.batches.export-download', [$batch->uuid, $export->id]))
            ->assertOk();

        $disposition = $response->headers->get('content-disposition');
        $this->assertStringEndsWith('.zip', explode('filename=', $disposition)[1] ?? '',
            'The download is named with something other than .zip while serving ZIP bytes.');

        // ⚠️ png is the IMAGE format inside; it must never become the container
        // extension. Pinned explicitly because svg was the value that shipped
        // broken and a png-only regression would otherwise slip through.
        $this->assertStringNotContainsString('.png', $disposition);

        $body = $response->streamedContent();
        $this->assertSame("PK\x03\x04", substr($body, 0, 4),
            'The response body is not a ZIP, so the .zip name is a lie — this is the '
            .'"bytes present is not pixels drawn" trap: asserting the name proves nothing '
            .'about the content.');
    }

    /**
     * Not-ready parts are refused. queued/processing have no archive yet and
     * failed never wrote one — serving any of them would be a 500 from the disk
     * read or an empty file presented as a result.
     */
    #[Test]
    public function a_part_that_is_not_ready_cannot_be_downloaded(): void
    {
        Storage::fake('local');
        $batch = SmartQrBatch::factory()->create();
        $admin = $this->adminWith(['view_qr_inventory']);

        foreach ([
            SmartQrExport::STATUS_QUEUED,
            SmartQrExport::STATUS_PROCESSING,
            SmartQrExport::STATUS_FAILED,
        ] as $i => $status) {
            $export = SmartQrExport::create([
                'batch_id' => $batch->id, 'part_number' => $i + 1, 'total_parts' => 3,
                'format' => 'svg', 'status' => $status,
            ]);

            $this->actingAs($admin, 'admin')
                ->get(route('admin.qr.batches.export-download', [$batch->uuid, $export->id]))
                ->assertNotFound();
        }
    }

    /**
     * ⚠️ A part belonging to ANOTHER batch 404s under this batch's URL.
     *
     * Both ids are in the path, so without the ownership check the route would
     * happily serve any part id under any batch — an admin-only surface, but
     * still a URL that means something other than what it says.
     */
    #[Test]
    public function a_part_from_another_batch_is_not_served_under_this_batch(): void
    {
        Storage::fake('local');
        $mine = SmartQrBatch::factory()->create();
        $theirs = SmartQrBatch::factory()->create();

        Storage::disk('local')->put('smartqr-exports/theirs.zip', 'x');
        $foreign = SmartQrExport::create([
            'batch_id' => $theirs->id, 'part_number' => 1, 'total_parts' => 1,
            'format' => 'svg', 'status' => SmartQrExport::STATUS_READY,
            'path' => 'smartqr-exports/theirs.zip',
        ]);

        $this->actingAs($this->adminWith(['view_qr_inventory']), 'admin')
            ->get(route('admin.qr.batches.export-download', [$mine->uuid, $foreign->id]))
            ->assertNotFound();
    }

    /** A row whose archive has been cleaned up is a 404, not a 500. */
    #[Test]
    public function a_ready_part_whose_file_is_missing_is_a_not_found(): void
    {
        Storage::fake('local');
        $batch = SmartQrBatch::factory()->create();

        $export = SmartQrExport::create([
            'batch_id' => $batch->id, 'part_number' => 1, 'total_parts' => 1,
            'format' => 'svg', 'status' => SmartQrExport::STATUS_READY,
            'path' => 'smartqr-exports/gone.zip',
        ]);

        $this->actingAs($this->adminWith(['view_qr_inventory']), 'admin')
            ->get(route('admin.qr.batches.export-download', [$batch->uuid, $export->id]))
            ->assertNotFound();
    }

    /** The show() page carries the parts as a prop — what the panel renders. */
    #[Test]
    public function the_batch_page_receives_its_export_parts_as_a_prop(): void
    {
        $batch = SmartQrBatch::factory()->create();
        SmartQrExport::create([
            'batch_id' => $batch->id, 'part_number' => 1, 'total_parts' => 2,
            'format' => 'svg', 'status' => SmartQrExport::STATUS_READY,
            'path' => 'smartqr-exports/a.zip',
        ]);
        SmartQrExport::create([
            'batch_id' => $batch->id, 'part_number' => 2, 'total_parts' => 2,
            'format' => 'svg', 'status' => SmartQrExport::STATUS_QUEUED,
        ]);

        $this->actingAs($this->adminWith(['view_qr_inventory']), 'admin')
            ->get(route('admin.qr.batches.show', $batch->uuid))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('exports', 2)
                ->where('exports.0.part_number', 1)
                ->where('exports.0.status', SmartQrExport::STATUS_READY)
                ->where('exports.1.status', SmartQrExport::STATUS_QUEUED)
            );
    }

    // ══ THE OLD PATH IS UNTOUCHED ══════════════════════════════════════════

    /**
     * ⚠️ THE SEPARATION, ASSERTED RATHER THAN ASSUMED — AND THE ASSERTION IS
     * "IT TOUCHES NOBODY ELSE'S ROW", NOT "IT CREATES NO ROW".
     *
     * The first version of this test asserted `SmartQrExport::count() === 0`
     * after running the untracked job. Mutation-checked: defaulting $exportId
     * to a truthy value left that GREEN, because track() only ever UPDATEs —
     * it never inserts — so no default can make a row appear from the job side
     * and the assertion could not fail for the reason it claimed to guard.
     *
     * What a bad default would actually do is silently rewrite whatever row
     * happens to hold that id. So an unrelated export row is planted first and
     * asserted untouched afterwards: that is the damage the null default
     * prevents, and it is what this now discriminates.
     */
    #[Test]
    public function the_untracked_inventory_path_touches_no_tracking_row_and_still_exports(): void
    {
        Storage::fake('local');
        $batch = $this->batchWithCodes(2);
        $codeIds = SmartQrCode::where('batch_id', $batch->id)->pluck('id')->all();

        // An unrelated part belonging to someone else's export, sitting at the
        // low id a careless default would collide with.
        $bystander = SmartQrExport::create([
            'batch_id' => $batch->id,
            'part_number' => 1,
            'total_parts' => 1,
            'format' => 'png',
            'status' => SmartQrExport::STATUS_QUEUED,
        ]);

        (new GenerateQrExportJob($codeIds, 'svg'))
            ->handle(app(SmartQrImageRenderer::class));

        $bystander->refresh();
        $this->assertSame(SmartQrExport::STATUS_QUEUED, $bystander->status,
            'The untracked Inventory path rewrote an unrelated export row — $exportId is no '
            .'longer defaulting to null.');
        $this->assertNull($bystander->path);
        $this->assertSame('png', $bystander->format);

        // Still exactly the one row that was planted; the job inserted nothing.
        $this->assertSame(1, SmartQrExport::count());

        $files = Storage::disk('local')->allFiles('smartqr-exports');
        $this->assertCount(1, $files, 'The untracked path stopped producing an archive.');
        $this->assertStringEndsWith('.zip', $files[0]);
    }
}
