<?php

namespace App\Modules\SmartQr\Console\Commands;

use App\Modules\SmartQr\Models\SmartQrDailyStat;
use App\Modules\SmartQr\Services\SmartQrAggregator;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;

/**
 * Deletes raw scan rows outside the retention window. R-4's amendment.
 *
 * ═══ ⚠️ THIS DELETES CUSTOMER DATA. THERE IS NO UNDO. ═══════════════════════
 *
 * Held to the same standard as `db:restore`, with the guards numbered so a
 * reader can see what each one refuses. `smart_qr_scan_events` is the largest
 * table in the system and the only unboundedly growing one — which is exactly
 * why a careless prune here is both attractive and irreversible.
 *
 * ⚠️ GUARD 2 is the one that matters and the reason R-4's amendment deferred
 * this command to the slice that builds the aggregates: pruning a day that was
 * never aggregated destroys the raw rows AND the summary that was supposed to
 * outlive them. See HAZARD H-4 in SmartQrAggregator for the mirror image.
 *
 * ⚠️ `--dry-run` is the safe default in every instruction: it reports per day
 * and touches nothing.
 */
class PruneSmartQrScansCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'smartqr:prune-scans
        {--days= : Retention window in days. Defaults to config, never shorter.}
        {--dry-run : Report what would be deleted and touch nothing}
        {--chunk=1000 : Rows per delete batch}
        {--force : Bypass the production confirmation. Does NOT widen the window.}';

    protected $description = 'Delete Smart QR raw scan events older than the retention window';

    public function handle(SmartQrAggregator $aggregator): int
    {
        // ── GUARD 6 — never guess the target ────────────────────────────────
        //
        // There is deliberately no --table and no --connection option. The
        // target is always `smart_qr_scan_events` on the configured connection,
        // so a typo cannot point this at something else. Copied from
        // DbRestoreCommand's guard 6 for the same reason.
        $table = 'smart_qr_scan_events';

        // ── GUARD 3 — the window can never be SHORTENED by a flag ───────────
        //
        // `--days` may only widen the window (delete less). A caller who passes
        // a smaller number than the configured retention is asking to delete
        // data the aggregates may not yet cover, and --force does not help.
        $configured = $aggregator->retentionDays();
        $requested = (int) ($this->option('days') ?? $configured);

        if ($requested < $configured) {
            $this->error(sprintf(
                'Refusing --days=%d: shorter than the configured retention of %d days. This flag '
                .'may only keep MORE data, never less. Change smartqr.scan_retention_days if the '
                .'policy itself is meant to change.',
                $requested,
                $configured
            ));

            return self::FAILURE;
        }

        $cutoff = now()->startOfDay()->subDays($requested);

        $this->info(sprintf('Retention: %d days. Deleting scans before %s.', $requested, $cutoff->toDateString()));

        // ── GUARD 4 — inspect BEFORE touching anything ──────────────────────
        //
        // Everything below is computed while the table is still intact, exactly
        // as db:restore verifies the archive before opening the database.
        $days = DB::table($table)
            ->selectRaw('DATE(scanned_at) as d, COUNT(*) as c')
            ->where('scanned_at', '<', $cutoff)
            ->groupBy('d')
            ->orderBy('d')
            ->get();

        if ($days->isEmpty()) {
            $this->info('Nothing outside the retention window.');

            return self::SUCCESS;
        }

        // ── ⚠️ GUARD 2 — REFUSE ANY DAY THAT WAS NEVER AGGREGATED ───────────
        //
        // THE important one. Deleting a day with no aggregate row destroys the
        // raw scans and the summary that was meant to survive them — R-4's
        // guarantee narrows to "the previous tenant's AGGREGATES stay
        // reachable", and this is what makes that true rather than aspirational.
        //
        // Not overridable by --force: there is no correct reason to delete a
        // day nothing has summarised.
        $aggregated = SmartQrDailyStat::query()
            ->whereIn('stat_date', $days->pluck('d')->all())
            ->pluck('stat_date')
            ->map(fn ($d) => $d->toDateString())
            ->all();

        $unaggregated = $days->pluck('d')->reject(fn ($d) => in_array($d, $aggregated, true));

        if ($unaggregated->isNotEmpty()) {
            $this->error('Refusing to prune: these days have no aggregate row, so deleting them '
                .'would destroy the data AND its summary.');

            foreach ($unaggregated->take(10) as $d) {
                $this->line('  '.$d);
            }

            if ($unaggregated->count() > 10) {
                $this->line(sprintf('  …and %d more', $unaggregated->count() - 10));
            }

            $this->line('');
            $this->line('Run `php artisan smartqr:aggregate --days=N` far enough back to cover them first.');

            return self::FAILURE;
        }

        $total = (int) $days->sum('c');

        foreach ($days as $d) {
            $this->line(sprintf('  %s — %d row(s)', $d->d, $d->c));
        }

        // ── GUARD 5 — dry run reports and stops ─────────────────────────────
        if ($this->option('dry-run')) {
            $this->info(sprintf('--dry-run: %d row(s) would be deleted. Nothing was changed.', $total));

            return self::SUCCESS;
        }

        // ── GUARD 1 — refuse in production unless confirmed ─────────────────
        //
        // ConfirmableTrait is the framework's own pattern and the one the other
        // destructive commands here use.
        if (! $this->confirmToProceed(sprintf('This will permanently delete %d scan row(s)', $total))) {
            return self::FAILURE;
        }

        // ── GUARD 7 — batched, never one unbounded DELETE ───────────────────
        //
        // This is the largest table in the system. A single statement would hold
        // locks for its whole duration on a table the public redirect path
        // writes to on every scan — so a prune could stall live customer traffic.
        $chunk = max(100, (int) $this->option('chunk'));
        $deleted = 0;

        do {
            $affected = DB::table($table)
                ->where('scanned_at', '<', $cutoff)
                ->limit($chunk)
                ->delete();

            $deleted += $affected;
        } while ($affected > 0);

        $this->info(sprintf('Deleted %d scan row(s) older than %s.', $deleted, $cutoff->toDateString()));

        return self::SUCCESS;
    }
}
