<?php

namespace App\Modules\SmartQr\Jobs;

use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Models\SmartQrExport;
use App\Modules\SmartQr\Services\SmartQrImageRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Builds a ZIP of rendered QR artwork. §14's "ZIP archive for bulk download".
 *
 * ⚠️ QUEUED, never in-request. 500 renders is seconds of CPU and tens of
 * megabytes; doing it in a web request means a timeout the admin reads as a
 * failure while the work continues invisibly.
 *
 * ⚠️ NO workspace context, and none is needed: codes are platform inventory
 * with no `workspace_id` (R-4). Classified NO_TENANT_DATA in the job guard for
 * the same reason as GenerateQrBatchJob.
 *
 * ═══ ⚠️ SLICE 2's LESSONS, CARRIED ════════════════════════════════════════
 *
 * The batch generator learned these the hard way and the reasoning transfers:
 *
 *   1. **Chunked.** Rendering 500 images with every string held in memory is a
 *      profile nobody measured. Each render is written into the archive and
 *      released.
 *   2. **A HALF-BUILT ARCHIVE IS DELETED, NOT LEFT.** A truncated ZIP downloads
 *      happily and fails to open — worse than an absent one, because the admin
 *      discovers it after sending it to a printer. Slice 2 refused to leave
 *      orphaned codes for the same reason.
 *   3. **The failure is recorded OUTSIDE the cleanup**, so the reason survives.
 *      Slice 2 wrote its failure reason inside the transaction being rolled
 *      back and lost it, leaving an operator with a stuck job and no
 *      explanation.
 *   4. **Idempotent on retry.** It rebuilds from the code-id list into a fresh
 *      temporary file rather than appending to whatever is on disk, so a retried
 *      job cannot produce an archive containing every code twice.
 */
class GenerateQrExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * ⚠️ THE CAP. §14's "ZIP archive of 500" is an example; this makes it a
     * limit.
     *
     * Refused up front with a message rather than discovered by timeout —
     * learning a ceiling by watching a job die is the worst way to learn it.
     */
    public const MAX_CODES = 500;

    /** The one directory both export kinds write to. */
    public const DIR = 'smartqr-exports';

    /** Ceiling on the daily sequence number — a guard, not a quota. */
    private const MAX_PER_DAY = 999;

    /**
     * ⚠️ PDF was withheld from the bulk export on an ASSUMPTION that Dompdf
     * would be too slow for 500 codes. Measured: 0.04 s and 6 KB per code, so
     * 500 is ~20 seconds and ~3 MB — cheaper than the PNG path it sat beside.
     * The assumption cost the owner a print-ready format for no reason.
     */
    public const FORMATS = ['svg', 'png', 'pdf'];

    /**
     * ⚠️ $exportId IS OPTIONAL AND TRAILING, AND THAT IS THE WHOLE CONTRACT.
     *
     * The Inventory bulk export (QrInventoryController::export) constructs this
     * job with three arguments and is deliberately not being changed. Adding a
     * NULLABLE trailing parameter means that call site keeps working untouched
     * and keeps behaving identically: null means "no tracking row", and every
     * status write below is skipped entirely. Anything that made the parameter
     * required, or that defaulted it to something truthy, would silently change
     * a path this slice is required to leave alone.
     *
     * @param  list<int>  $codeIds
     * @param  int|null  $exportId  smart_qr_exports row to track, or null for the
     *                              untracked Inventory path.
     */
    public function __construct(
        public readonly array $codeIds,
        public readonly string $format,
        public readonly ?int $adminId = null,
        public readonly ?int $exportId = null,
    ) {}

    /**
     * Update the tracking row, if there is one.
     *
     * ⚠️ Every status write goes through here so the null case is handled in ONE
     * place. A row that has been deleted (its batch removed mid-export, which
     * cascades) is a no-op rather than an error: the export is moot at that
     * point, and throwing here would fail a job whose actual work succeeded.
     *
     * @param  array<string, mixed>  $attributes
     */
    /**
     * Claim an unused `Export_30-Aug-2026_01.zip` name, atomically.
     *
     * ═══ ⚠️ WHY THIS IS NOT count(files)+1 ═════════════════════════════════
     *
     * A 20-part batch export dispatches twenty jobs at once. Counting the
     * directory and adding one is a read followed by a write with nothing in
     * between: two workers counting 3 both pick 04, and the second `put()`
     * OVERWRITES the first worker's archive. Nothing errors — an admin simply
     * downloads one part twice and never receives the other.
     *
     * `fopen($path, 'x')` fails if the file exists and is atomic on a local
     * filesystem, so the first worker to claim a number owns it. The loser
     * moves to the next number rather than clobbering.
     *
     * ⚠️ THE CLAIMED FILE IS AN EMPTY PLACEHOLDER until the archive is moved
     * over it on success, and the catch block deletes it on failure. That is
     * why the name is claimed HERE and not after the ZIP is built: claiming
     * late reopens the race it exists to close.
     *
     * ⚠️ ONE NAMING POINT FOR BOTH EXPORT KINDS. The inventory screen's ad-hoc
     * export and the batch-scoped export both dispatch THIS job, so the format
     * cannot drift between them.
     */
    private static function claimFilename(Filesystem $disk): string
    {
        $date = now()->format('j-M-Y');

        for ($n = 1; $n <= self::MAX_PER_DAY; $n++) {
            $relative = sprintf('%s/Export_%s_%02d.zip', self::DIR, $date, $n);
            $handle = @fopen($disk->path($relative), 'x');

            if ($handle !== false) {
                fclose($handle);

                return $relative;
            }
        }

        // 999 archives in one day is not a naming problem, it is a runaway
        // caller. Failing loudly beats silently reusing a name.
        throw new \RuntimeException(sprintf(
            'Exhausted %d export filenames for %s.', self::MAX_PER_DAY, $date
        ));
    }

    /** @param  array<string, mixed>  $attributes */
    private function track(array $attributes): void
    {
        if ($this->exportId === null) {
            return;
        }

        SmartQrExport::whereKey($this->exportId)->update($attributes);
    }

    public function handle(SmartQrImageRenderer $renderer): void
    {
        $this->track(['status' => SmartQrExport::STATUS_PROCESSING]);

        $format = in_array($this->format, self::FORMATS, true) ? $this->format : 'svg';

        // The cap is enforced at dispatch too; this is the backstop for a job
        // replayed from a queue payload written before the limit existed.
        $ids = array_slice($this->codeIds, 0, self::MAX_CODES);

        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIR);

        $relative = self::claimFilename($disk);

        // ⚠️ Built at a TEMPORARY path and moved on success, so a partial
        // archive never occupies the name a download would resolve.
        $temp = tempnam(sys_get_temp_dir(), 'smartqr');

        $zip = new ZipArchive;

        if ($zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($temp);
            throw new \RuntimeException('Could not open a ZIP archive for writing.');
        }

        // ⚠️ RESOLVED LOGO PATHS, CACHED BY BATCH ID.
        //
        // The logo is a property of the BATCH, and an export selection can span
        // batches — the inventory screen selects codes, not runs. Resolving per
        // CODE would run an exists() and an is_file() five hundred times for
        // what is at most a handful of distinct answers. Keyed by batch id, with
        // null cached as null (array_key_exists, not ??=, so "no logo" is
        // remembered rather than re-resolved 499 times).
        //
        // @var array<int, string|null> $logoPaths
        $logoPaths = [];

        try {
            foreach (array_chunk($ids, 50) as $chunk) {
                // ⚠️ EAGER-LOADED. Without this the batch is lazy-loaded per
                // code — 500 extra queries inside the render loop.
                foreach (SmartQrCode::whereIn('id', $chunk)->with('batch')->get() as $code) {
                    $url = route('smartqr.scan', ['token' => $code->public_token]);

                    if (! array_key_exists($code->batch_id, $logoPaths)) {
                        $logoPaths[$code->batch_id] = $renderer->batchLogoPath($code->batch);
                    }

                    $logo = $logoPaths[$code->batch_id];

                    $rendered = match ($format) {
                        'png' => $renderer->png($url, $code->serial_number, $logo),
                        'pdf' => $renderer->pdf($url, $code->serial_number, $logo),
                        default => $renderer->svg($url, $code->serial_number, $logo),
                    };

                    $zip->addFromString($code->serial_number.'.'.$format, $rendered['data']);

                    // Released immediately — see lesson 1.
                    unset($rendered);
                }
            }

            $zip->close();

            $disk->put($relative, file_get_contents($temp));

            // ⚠️ Written INSIDE the try, immediately after the file lands. A
            // row marked ready before the put would name an archive that does
            // not exist yet; after the catch, a throw would leave it processing
            // forever.
            $this->track([
                'status' => SmartQrExport::STATUS_READY,
                'path' => $relative,
            ]);
        } catch (\Throwable $e) {
            // ⚠️ LESSON 2 + 3. Clean up the partial artefact, then record the
            // reason OUTSIDE the cleanup so it survives, then rethrow so the
            // queue marks the job failed rather than silently succeeding.
            // ⚠️ `@` DOES NOT SUPPRESS EXCEPTIONS IN PHP 8 — only diagnostics.
            //
            // This was `@$zip->close();`. Closing an already-closed archive
            // throws ValueError('Invalid or uninitialized Zip object'), and it
            // threw from inside the catch — so the ORIGINAL failure was replaced
            // by a misleading one, and every line below (the Log::error and the
            // track(FAILED)) never ran. A genuinely failed export therefore
            // stayed `processing` forever with nothing in the log.
            //
            // Measured: first close() returns true, second throws.
            try {
                $zip->close();
            } catch (\Throwable) {
                // Already closed, or never opened. Either way it is not the
                // failure worth reporting — $e is.
            }

            @unlink($temp);

            if ($disk->exists($relative)) {
                $disk->delete($relative);
            }

            Log::error('smart_qr.export.failed', [
                'codes' => count($ids),
                'format' => $format,
                'admin_id' => $this->adminId,
                'export_id' => $this->exportId,
                'error' => $e->getMessage(),
            ]);

            // ⚠️ Recorded alongside the log line, not instead of it, and with
            // the SAME value — slice 2's lesson that a failure reason written
            // where it cannot survive leaves an operator with a stuck job and
            // no explanation. The log serves whoever reads logs; this column
            // serves the admin looking at the batch's export panel, who will
            // never see a log line.
            $this->track([
                'status' => SmartQrExport::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        @unlink($temp);

        Log::info('smart_qr.export.ready', [
            'path' => $relative,
            'codes' => count($ids),
            'format' => $format,
            'admin_id' => $this->adminId,
        ]);
    }

    /**
     * ⚠️ THE ROW WOULD OTHERWISE BE STRANDED FOREVER.
     *
     * handle()'s own catch records FAILED and rethrows — but it can only run if
     * handle() runs. MaxAttemptsExceededException and the queue's timeout are
     * raised by the WORKER, before or instead of handle(), so nothing inside it
     * ever executes and the row keeps whatever status it last held: `queued` if
     * the job was never picked up, `processing` if it timed out mid-build.
     *
     * Five such rows existed in development (ids 5-9, all `queued`, no file),
     * left by a worker that was up but consuming nothing. Nothing in the
     * application could ever move them, and the batch page showed them as
     * pending indefinitely.
     *
     * ⚠️ Only touches rows that are still in flight. A row already FAILED by
     * handle()'s catch keeps that reason — this hook must not overwrite the
     * specific message with a generic one.
     */
    public function failed(?\Throwable $e): void
    {
        if ($this->exportId === null) {
            return;
        }

        SmartQrExport::whereKey($this->exportId)
            ->whereIn('status', [SmartQrExport::STATUS_QUEUED, SmartQrExport::STATUS_PROCESSING])
            ->update([
                'status' => SmartQrExport::STATUS_FAILED,
                'error' => Str::limit($e?->getMessage() ?? 'The export job failed without reaching the builder.', 500),
            ]);
    }
}
