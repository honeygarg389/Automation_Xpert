<?php

namespace App\Modules\SmartQr\Services;

use App\Modules\SmartQr\Models\SmartQrConversionEvent;
use App\Modules\SmartQr\Models\SmartQrDailyStat;
use App\Modules\SmartQr\Models\SmartQrScanEvent;
use App\Modules\SmartQr\Support\SmartQrStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every number the customer dashboard shows. §11's KPI cards.
 *
 * ─── ⚠️ THIS FILE IS THE SLICE-7 SEAM ──────────────────────────────────────
 *
 * Five of the six KPI queries below read RAW tables — `smart_qr_scan_events`
 * and `smart_qr_conversion_events` — because slice 7's daily aggregates do not
 * exist yet.
 *
 * They are all here, in one class, deliberately: when the aggregates land,
 * slice 7 changes THIS FILE and nothing else. The alternative — the same
 * COUNT(*) spread across three controllers — makes that swap a four-file diff
 * nobody can review as a single change.
 *
 * ⚠️ The one that will NOT move is the Activity feed. §11 wants a per-scan
 * list, which is inherently raw-table: an aggregate cannot serve individual
 * rows. It stays a direct query, on the only unboundedly growing table in the
 * project, which is why it is paginated hard and why R-4's 90-day retention
 * matters to it more than to anything else.
 *
 * ─── ⚠️ EVERY READ GOES THROUGH SmartQrAccess ───────────────────────────────
 *
 * `SmartQrCode` has no global scope (R-4), so a raw query here would return
 * every tenant's rows with nothing to stop it, and the failure would be a list
 * that quietly includes codes belonging to somebody else.
 * `SmartQrAccessGuardTest` fails the build on any raw static query against that
 * model outside the access service and the admin namespace — and this namespace
 * is deliberately NOT on that allowlist. (The guard is a text scan, so this
 * comment avoids spelling the pattern it looks for.)
 */
class SmartQrMetrics
{
    public function __construct(private readonly SmartQrAccess $access) {}

    /**
     * The Overview cards.
     *
     * ⚠️ Bots are excluded from every scan figure. They are RECORDED (slice 4
     * flags rather than drops, because a preview crawler is evidence a link was
     * shared) but they are not scans a customer should see counted.
     *
     * @return array<string, int|float|null>
     */
    public function overview(int $workspaceId): array
    {
        // ⚠️ HISTORICAL metrics use EVERY assignment this workspace has ever
        // held, not just current ones. R-4 keeps a previous tenant's own scans
        // visible to them after a reassignment — using the current-only set
        // zeroes their history the moment a code changes hands.
        $assignmentIds = $this->access->allAssignmentsFor($workspaceId)->pluck('id');

        $codes = $this->access->codesFor($workspaceId)->count();

        $active = $this->access->assignmentsFor($workspaceId)
            ->where('status', SmartQrStatus::ASSIGNMENT_ACTIVE)->count();

        $totals = $this->scanAndConversionTotals($assignmentIds);

        return [
            // ⚠️ These three STAY live. They are "what is assigned right now",
            // not a time series — an aggregate could only tell you what was
            // assigned on some past day.
            'total_codes' => $codes,
            'active_codes' => $active,
            'inactive_codes' => max(0, $codes - $active),

            'total_scans' => $totals['scans'],
            'unique_scans' => $totals['unique_scans'],
            'attributed_messages' => $totals['attributed_messages'],
            'attributed_new_contacts' => $totals['attributed_new_contacts'],

            // ⚠️ §12's DEFINITION, and slice 6's number CHANGED here.
            //
            // §12: "Scan-to-Message Rate = Unique Customers Messaged / Unique
            // Valid Scans × 100".
            //
            // Slice 6 shipped `attributed_messages / all non-bot scans` — a
            // different numerator AND a different denominator. That was wrong
            // against an explicit spec definition, and it is corrected rather
            // than left to look like drift: `attributed_unique_contacts` is the
            // piece slice 6 could not compute, and slice 7's aggregates add it.
            //
            // ⚠️ null, not 0, when there are no scans. "0%" reads as "nobody
            // responded"; null reads as "nothing has happened yet", which is
            // the truth. §12 asks only that divide-by-zero be prevented — it
            // does not ask for a misleading zero.
            'attributed_message_rate' => $totals['unique_scans'] > 0
                ? round(($totals['attributed_unique_contacts'] / $totals['unique_scans']) * 100, 1)
                : null,
        ];
    }

    /**
     * ⚠️ THE HYBRID: history from aggregates, TODAY from raw.
     *
     * Today is still accumulating and the nightly job has not seen it. Showing
     * yesterday's figure as today's would be wrong; showing nothing would make a
     * customer think their scans were not recorded. So the current day is
     * computed live — it is one partial day of rows against an indexed column —
     * and everything before it comes from the aggregates.
     *
     * This is also why the dashboard never depends on the scheduler having run.
     *
     * @param  Collection<int, int>  $assignmentIds
     * @return array<string, int>
     */
    private function scanAndConversionTotals($assignmentIds): array
    {
        $zero = [
            'scans' => 0, 'unique_scans' => 0,
            'attributed_messages' => 0, 'attributed_unique_contacts' => 0,
            'attributed_new_contacts' => 0,
        ];

        if ($assignmentIds->isEmpty()) {
            return $zero;
        }

        $today = now()->startOfDay();

        // ── Historical: the aggregates ──────────────────────────────────────
        $agg = SmartQrDailyStat::query()
            ->whereIn('smart_qr_assignment_id', $assignmentIds)
            ->where('stat_date', '<', $today->toDateString())
            ->selectRaw('COALESCE(SUM(scans),0) as scans')
            ->selectRaw('COALESCE(SUM(unique_scans),0) as unique_scans')
            ->selectRaw('COALESCE(SUM(attributed_messages),0) as attributed_messages')
            ->selectRaw('COALESCE(SUM(attributed_unique_contacts),0) as attributed_unique_contacts')
            ->selectRaw('COALESCE(SUM(attributed_new_contacts),0) as attributed_new_contacts')
            ->first();

        // ── Today: raw ──────────────────────────────────────────────────────
        $todayScans = DB::table('smart_qr_scan_events')
            ->whereIn('smart_qr_assignment_id', $assignmentIds)
            ->where('scanned_at', '>=', $today)
            ->selectRaw('SUM(CASE WHEN is_bot = 0 THEN 1 ELSE 0 END) as scans')
            ->selectRaw('SUM(CASE WHEN is_bot = 0 AND is_unique = 1 THEN 1 ELSE 0 END) as unique_scans')
            ->first();

        $todayConv = DB::table('smart_qr_conversion_events')
            ->whereIn('smart_qr_assignment_id', $assignmentIds)
            ->where('occurred_at', '>=', $today)
            ->selectRaw('SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) as messages', [SmartQrConversionEvent::TYPE_CUSTOMER_MESSAGED])
            ->selectRaw('COUNT(DISTINCT CASE WHEN type = ? THEN contact_id END) as unique_contacts', [SmartQrConversionEvent::TYPE_CUSTOMER_MESSAGED])
            ->selectRaw('SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) as new_contacts', [SmartQrConversionEvent::TYPE_NEW_CONTACT])
            ->first();

        return [
            'scans' => (int) ($agg->scans ?? 0) + (int) ($todayScans->scans ?? 0),
            'unique_scans' => (int) ($agg->unique_scans ?? 0) + (int) ($todayScans->unique_scans ?? 0),
            'attributed_messages' => (int) ($agg->attributed_messages ?? 0) + (int) ($todayConv->messages ?? 0),
            // ⚠️ Summing daily distinct counts over-counts a customer who
            // messaged on two different days. Accepted deliberately: the
            // alternative is keeping every contact id per day, which is a second
            // scan-sized table. Documented rather than silently approximate.
            'attributed_unique_contacts' => (int) ($agg->attributed_unique_contacts ?? 0) + (int) ($todayConv->unique_contacts ?? 0),
            'attributed_new_contacts' => (int) ($agg->attributed_new_contacts ?? 0) + (int) ($todayConv->new_contacts ?? 0),
        ];
    }

    /**
     * Per-code figures for the My QR Codes table.
     *
     * @return array<int, array{scans: int, attributed_messages: int, last_scan_at: string|null}>
     */
    public function perAssignment(int $workspaceId): array
    {
        // Current only, deliberately: this table lists the codes the customer
        // holds NOW, so a row for a code they no longer have would be noise.
        $assignmentIds = $this->access->assignmentsFor($workspaceId)->pluck('id')->all();

        if ($assignmentIds === []) {
            return [];
        }

        $scans = DB::table('smart_qr_scan_events')
            ->select('smart_qr_assignment_id', DB::raw('COUNT(*) as c'), DB::raw('MAX(scanned_at) as last_scan'))
            ->whereIn('smart_qr_assignment_id', $assignmentIds)
            ->where('is_bot', false)
            ->groupBy('smart_qr_assignment_id')
            ->get()
            ->keyBy('smart_qr_assignment_id');

        $messages = DB::table('smart_qr_conversion_events')
            ->select('smart_qr_assignment_id', DB::raw('COUNT(*) as c'))
            ->whereIn('smart_qr_assignment_id', $assignmentIds)
            ->where('type', SmartQrConversionEvent::TYPE_CUSTOMER_MESSAGED)
            ->groupBy('smart_qr_assignment_id')
            ->get()
            ->keyBy('smart_qr_assignment_id');

        $out = [];

        foreach ($assignmentIds as $id) {
            $out[$id] = [
                'scans' => (int) ($scans[$id]->c ?? 0),
                'attributed_messages' => (int) ($messages[$id]->c ?? 0),
                'last_scan_at' => $scans[$id]->last_scan ?? null,
            ];
        }

        return $out;
    }

    /**
     * The Activity feed — raw scans, newest first.
     *
     * ⚠️ Bots INCLUDED here, flagged, unlike the counters above. The feed is a
     * log rather than a metric: hiding crawler hits would make an operator
     * wonder why a scan they can see in their own analytics is missing.
     *
     * ⚠️ Typed to Model rather than SmartQrScanEvent, and that is upstream's
     * shape rather than a shrug: `SmartQrAccess`'s builders carry no generic
     * parameter — four of this module's six pre-existing PHPStan errors are
     * exactly that — so the paginator's item type erases to Model. Narrowing it
     * here would be a claim the call does not support. It tightens for free the
     * day SmartQrAccess is annotated.
     *
     * @return LengthAwarePaginator<int, Model>
     */
    public function activity(int $workspaceId, int $perPage = 50): LengthAwarePaginator
    {
        return $this->access->scanEventsFor($workspaceId)
            ->with(['assignment:id,name,qr_type,smart_qr_code_id', 'assignment.code:id,serial_number'])
            ->orderByDesc('scanned_at')
            ->paginate($perPage)
            ->withQueryString();
    }
}
