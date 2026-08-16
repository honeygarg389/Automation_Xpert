<?php

namespace App\Modules\SmartQr\Services;

use App\Modules\SmartQr\Models\SmartQrConversionEvent;
use App\Modules\SmartQr\Models\SmartQrDailyStat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds §10's daily aggregates from raw scan and conversion rows.
 *
 * ═══ ⚠️ HAZARD H-4 — THE AGGREGATOR/PRUNE INTERLOCK ═══════════════════════
 *
 * **Two individually correct jobs that together erase the history the prune
 * exists to preserve.** Named as its own hazard because both halves look right
 * in isolation and the damage is silent.
 *
 * The shape:
 *
 *   1. `smartqr:prune-scans` deletes raw scan rows older than the retention
 *      window. Safe on its own — the aggregates hold the summary.
 *   2. `smartqr:aggregate` recomputes a day from raw rows and `updateOrCreate`s
 *      the result. Safe on its own — idempotent, corrects late arrivals.
 *   3. Run (2) for a day already pruned by (1), and it computes **zero** from
 *      the now-empty raw table and OVERWRITES a correct historical aggregate
 *      with zeros.
 *
 * Nothing errors. Nothing logs. The number simply becomes wrong, in the only
 * copy of it that still exists.
 *
 * ⚠️ THE INTERLOCK: `aggregate()` REFUSES any date older than the retention
 * window, and **there is no force flag that overrides it** — there is no
 * correct reason to want it. A caller that genuinely needs an old day needs the
 * raw data back, which the prune has already made impossible.
 *
 * The guarding test asserts the EXISTING ROW IS UNCHANGED, not that an
 * exception was thrown: an implementation that throws after writing zeros would
 * pass an exception-only assertion.
 */
class SmartQrAggregator
{
    public function retentionDays(): int
    {
        return (int) config('smartqr.scan_retention_days', 90);
    }

    /**
     * The oldest date the aggregator may compute.
     *
     * Anything before this may have had its raw rows pruned, so recomputing it
     * would produce zero rather than the truth.
     */
    public function earliestComputableDate(): Carbon
    {
        return now()->startOfDay()->subDays($this->retentionDays());
    }

    public function isComputable(Carbon $date): bool
    {
        return $date->startOfDay()->gte($this->earliestComputableDate());
    }

    /**
     * Build (or rebuild) one day for every assignment that saw activity.
     *
     * @return int the number of aggregate rows written
     *
     * @throws RuntimeException when the date is outside the retention window
     */
    public function aggregate(Carbon $date): int
    {
        $day = $date->copy()->startOfDay();

        // ⚠️ HAZARD H-4. Refused loudly, and deliberately NOT overridable.
        if (! $this->isComputable($day)) {
            throw new RuntimeException(sprintf(
                'Refusing to aggregate %s: it is older than the %d-day retention window, so its '
                .'raw scans may already have been pruned. Recomputing would write ZERO over a '
                .'correct historical aggregate — the only copy of that day that still exists. '
                .'There is no --force for this.',
                $day->toDateString(),
                $this->retentionDays()
            ));
        }

        $start = $day->copy();
        $end = $day->copy()->endOfDay();

        $scans = $this->scanTotals($start, $end);
        $conversions = $this->conversionTotals($start, $end);

        // Every assignment that saw either kind of activity.
        $assignmentIds = collect($scans)->keys()->merge(collect($conversions)->keys())->unique();

        foreach ($assignmentIds as $assignmentId) {
            $s = $scans[$assignmentId] ?? [];
            $c = $conversions[$assignmentId] ?? [];

            // ⚠️ updateOrCreate against the unique (assignment, date) grain —
            // this is what makes a re-run correct rather than duplicating.
            SmartQrDailyStat::updateOrCreate(
                ['smart_qr_assignment_id' => $assignmentId, 'stat_date' => $day->toDateString()],
                [
                    'scans' => $s['scans'] ?? 0,
                    'unique_scans' => $s['unique_scans'] ?? 0,
                    'bot_scans' => $s['bot_scans'] ?? 0,
                    'attributed_messages' => $c['messages'] ?? 0,
                    'attributed_unique_contacts' => $c['unique_contacts'] ?? 0,
                    'attributed_new_contacts' => $c['new_contacts'] ?? 0,
                    'attributed_conversations_started' => $c['conversations'] ?? 0,
                ]
            );
        }

        return $assignmentIds->count();
    }

    /**
     * @return array<int, array{scans: int, unique_scans: int, bot_scans: int}>
     */
    private function scanTotals(Carbon $start, Carbon $end): array
    {
        return DB::table('smart_qr_scan_events')
            ->selectRaw('smart_qr_assignment_id')
            // ⚠️ Bots excluded from `scans` and counted separately. Slice 4
            // records them; the customer's figures must not include them.
            ->selectRaw('SUM(CASE WHEN is_bot = 0 THEN 1 ELSE 0 END) as scans')
            ->selectRaw('SUM(CASE WHEN is_bot = 0 AND is_unique = 1 THEN 1 ELSE 0 END) as unique_scans')
            ->selectRaw('SUM(CASE WHEN is_bot = 1 THEN 1 ELSE 0 END) as bot_scans')
            ->whereBetween('scanned_at', [$start, $end])
            ->groupBy('smart_qr_assignment_id')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->smart_qr_assignment_id => [
                'scans' => (int) $r->scans,
                'unique_scans' => (int) $r->unique_scans,
                'bot_scans' => (int) $r->bot_scans,
            ]])
            ->all();
    }

    /**
     * @return array<int, array{messages: int, unique_contacts: int, new_contacts: int, conversations: int}>
     */
    private function conversionTotals(Carbon $start, Carbon $end): array
    {
        return DB::table('smart_qr_conversion_events')
            ->selectRaw('smart_qr_assignment_id')
            ->selectRaw('SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) as messages', [SmartQrConversionEvent::TYPE_CUSTOMER_MESSAGED])
            // ⚠️ DISTINCT contacts, not a count of events. §12's rate is
            // "Unique Customers Messaged", and two messages from one person are
            // one customer. Counting events would inflate the numerator and make
            // the rate exceed 100%.
            ->selectRaw('COUNT(DISTINCT CASE WHEN type = ? THEN contact_id END) as unique_contacts', [SmartQrConversionEvent::TYPE_CUSTOMER_MESSAGED])
            ->selectRaw('SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) as new_contacts', [SmartQrConversionEvent::TYPE_NEW_CONTACT])
            ->selectRaw('SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) as conversations', [SmartQrConversionEvent::TYPE_CONVERSATION_STARTED])
            ->whereBetween('occurred_at', [$start, $end])
            ->groupBy('smart_qr_assignment_id')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->smart_qr_assignment_id => [
                'messages' => (int) $r->messages,
                'unique_contacts' => (int) $r->unique_contacts,
                'new_contacts' => (int) $r->new_contacts,
                'conversations' => (int) $r->conversations,
            ]])
            ->all();
    }
}
