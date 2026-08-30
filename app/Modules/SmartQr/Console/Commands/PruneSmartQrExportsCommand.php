<?php

namespace App\Modules\SmartQr\Console\Commands;

use App\Modules\SmartQr\Jobs\GenerateQrExportJob;
use App\Modules\SmartQr\Models\SmartQrExport;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\Storage;

/**
 * Reclaims export ZIPs outside the retention window. The prune owed since slice 8.
 *
 * ═══ ⚠️ WHAT MAKES THIS DIFFERENT FROM smartqr:prune-scans ══════════════════
 *
 * That command deletes customer data and its guards are about not destroying
 * something irreplaceable. This one deletes REBUILDABLE convenience files, so
 * the risk is inverted: the danger is not losing data, it is breaking a link an
 * admin is looking at.
 *
 * The directory holds BOTH kinds of export under one naming scheme, and nothing
 * in a filename says which is which:
 *
 *   - ad-hoc inventory archives, tracked by nothing but the file itself
 *   - batch archives, each with a smart_qr_exports row whose `path` points at it
 *     and whose status the batch page renders
 *
 * ⚠️ SO AGE ALONE MUST NEVER DECIDE. Deleting a tracked file by age leaves a row
 * saying `ready` whose download 404s — the export's own status becomes a lie.
 * Tracked files are EXPIRED instead: the file goes, `path` is nulled and the row
 * moves to a terminal state the UI can render honestly. The row survives, because
 * "an export was built on this date" is worth keeping after the archive is not.
 *
 * ⚠️ `--dry-run` reports and touches nothing. `--days` may only WIDEN the window,
 * never shorten it — copied from prune-scans, for the same reason: a flag that
 * can delete MORE than policy is a flag that will eventually be typed wrong.
 *
 * ⚠️ There is deliberately no --path and no --disk. The target is always the
 * export directory on the local disk, so a typo cannot repoint this at another
 * tree. That is prune-scans' guard 6 and DbRestoreCommand's before it.
 */
class PruneSmartQrExportsCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'smartqr:prune-exports
        {--days= : Retention window in days. Defaults to config, never shorter.}
        {--dry-run : Report what would be reclaimed and touch nothing}
        {--force : Bypass the production confirmation. Does NOT widen the window.}';

    protected $description = 'Delete Smart QR export archives older than the retention window';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $dir = GenerateQrExportJob::DIR;

        // ── GUARD 3 — the window can never be SHORTENED by a flag ───────────
        $configured = (int) config('smartqr.export_retention_days', 7);
        $requested = (int) ($this->option('days') ?? $configured);

        if ($requested < $configured) {
            $this->error(sprintf(
                'Refusing --days=%d: shorter than the configured retention of %d days. This flag '
                .'may only keep MORE files, never fewer. Change smartqr.export_retention_days if '
                .'the policy itself is meant to change.',
                $requested,
                $configured
            ));

            return self::FAILURE;
        }

        if (! $disk->exists($dir)) {
            $this->info('No export directory; nothing to do.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($requested);

        $this->info(sprintf('Retention: %d days. Reclaiming archives built before %s.', $requested, $cutoff->toDateTimeString()));

        // ── GUARD 4 — inspect BEFORE touching anything ──────────────────────
        $stale = collect($disk->files($dir))
            ->filter(fn (string $f) => str_ends_with($f, '.zip'))
            ->filter(fn (string $f) => $disk->lastModified($f) < $cutoff->getTimestamp())
            ->values();

        if ($stale->isEmpty()) {
            $this->info('Nothing outside the retention window.');

            return self::SUCCESS;
        }

        // ── ⚠️ GUARD 2 — SEPARATE TRACKED FROM UNTRACKED ────────────────────
        //
        // THE important one, and the mirror of prune-scans' guard 2: the same
        // action means different things to the two populations, so they are
        // never treated as one list.
        $trackedPaths = SmartQrExport::query()
            ->whereNotNull('path')
            ->whereIn('path', $stale->all())
            ->pluck('id', 'path');

        $tracked = $stale->filter(fn (string $f) => $trackedPaths->has($f))->values();
        $untracked = $stale->reject(fn (string $f) => $trackedPaths->has($f))->values();

        $bytes = $stale->sum(fn (string $f) => $disk->size($f));

        $this->line(sprintf('  %d untracked archive(s) — will be DELETED', $untracked->count()));
        $this->line(sprintf('  %d tracked archive(s)   — file deleted, row EXPIRED (kept)', $tracked->count()));
        $this->line(sprintf('  %s MB total', number_format($bytes / 1048576, 1)));

        // ── GUARD 5 — dry run reports and stops ─────────────────────────────
        if ($this->option('dry-run')) {
            foreach ($untracked->take(10) as $f) {
                $this->line('    delete  '.basename($f));
            }

            foreach ($tracked->take(10) as $f) {
                $this->line(sprintf('    expire  %s (export #%s)', basename($f), $trackedPaths[$f]));
            }

            $this->info('--dry-run: nothing was changed.');

            return self::SUCCESS;
        }

        // ── GUARD 1 — refuse in production unless confirmed ─────────────────
        if (! $this->confirmToProceed(sprintf('This will permanently delete %d export archive(s)', $stale->count()))) {
            return self::FAILURE;
        }

        foreach ($untracked as $f) {
            $disk->delete($f);
        }

        // ⚠️ THE ROW IS UPDATED BEFORE ITS FILE IS DELETED. The reverse order
        // leaves a window where the row still says `ready` and points at a path
        // that is already gone — which is exactly the state this command exists
        // to avoid producing.
        foreach ($tracked as $f) {
            SmartQrExport::whereKey($trackedPaths[$f])->update([
                'status' => SmartQrExport::STATUS_EXPIRED,
                'path' => null,
            ]);

            $disk->delete($f);
        }

        $this->info(sprintf(
            'Reclaimed %s MB: deleted %d untracked archive(s), expired %d tracked one(s).',
            number_format($bytes / 1048576, 1),
            $untracked->count(),
            $tracked->count()
        ));

        return self::SUCCESS;
    }
}
