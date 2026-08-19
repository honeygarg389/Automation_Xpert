<?php

namespace App\Modules\SmartQr\Jobs;

use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Services\SmartQrImageRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

    /**
     * ⚠️ PDF was withheld from the bulk export on an ASSUMPTION that Dompdf
     * would be too slow for 500 codes. Measured: 0.04 s and 6 KB per code, so
     * 500 is ~20 seconds and ~3 MB — cheaper than the PNG path it sat beside.
     * The assumption cost the owner a print-ready format for no reason.
     */
    public const FORMATS = ['svg', 'png', 'pdf'];

    /** @param list<int> $codeIds */
    public function __construct(
        public readonly array $codeIds,
        public readonly string $format,
        public readonly ?int $adminId = null,
    ) {}

    public function handle(SmartQrImageRenderer $renderer): void
    {
        $format = in_array($this->format, self::FORMATS, true) ? $this->format : 'svg';

        // The cap is enforced at dispatch too; this is the backstop for a job
        // replayed from a queue payload written before the limit existed.
        $ids = array_slice($this->codeIds, 0, self::MAX_CODES);

        $relative = 'smartqr-exports/'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.zip';
        $disk = Storage::disk('local');
        $disk->makeDirectory('smartqr-exports');

        // ⚠️ Built at a TEMPORARY path and moved on success, so a partial
        // archive never occupies the name a download would resolve.
        $temp = tempnam(sys_get_temp_dir(), 'smartqr');

        $zip = new ZipArchive;

        if ($zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($temp);
            throw new \RuntimeException('Could not open a ZIP archive for writing.');
        }

        try {
            foreach (array_chunk($ids, 50) as $chunk) {
                foreach (SmartQrCode::whereIn('id', $chunk)->get() as $code) {
                    $url = route('smartqr.scan', ['token' => $code->public_token]);

                    $rendered = match ($format) {
                        'png' => $renderer->png($url, $code->serial_number),
                        'pdf' => $renderer->pdf($url, $code->serial_number),
                        default => $renderer->svg($url, $code->serial_number),
                    };

                    $zip->addFromString($code->serial_number.'.'.$format, $rendered['data']);

                    // Released immediately — see lesson 1.
                    unset($rendered);
                }
            }

            $zip->close();

            $disk->put($relative, file_get_contents($temp));
        } catch (\Throwable $e) {
            // ⚠️ LESSON 2 + 3. Clean up the partial artefact, then record the
            // reason OUTSIDE the cleanup so it survives, then rethrow so the
            // queue marks the job failed rather than silently succeeding.
            @$zip->close();

            @unlink($temp);

            if ($disk->exists($relative)) {
                $disk->delete($relative);
            }

            Log::error('smart_qr.export.failed', [
                'codes' => count($ids),
                'format' => $format,
                'admin_id' => $this->adminId,
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
}
