<?php

namespace App\Modules\SmartQr\Services;

use App\Modules\SmartQr\Models\SmartQrConversionEvent;
use App\Modules\SmartQr\Support\SmartQrStatus;
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
 * `SmartQrAccessGuardTest` fails the build on any `SmartQrCode::` outside the
 * access service and the admin namespace — and this namespace is deliberately
 * NOT on that allowlist.
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
        $assignmentIds = $this->access->assignmentsFor($workspaceId)->pluck('id');

        $codes = $this->access->codesFor($workspaceId)->count();

        $active = $this->access->assignmentsFor($workspaceId)
            ->where('status', SmartQrStatus::ASSIGNMENT_ACTIVE)->count();

        $scans = $this->scanQuery($workspaceId)->count();
        $unique = $this->scanQuery($workspaceId)->where('is_unique', true)->count();

        $attributedMessages = $this->conversions($assignmentIds, SmartQrConversionEvent::TYPE_CUSTOMER_MESSAGED);
        $attributedContacts = $this->conversions($assignmentIds, SmartQrConversionEvent::TYPE_NEW_CONTACT);

        return [
            'total_codes' => $codes,
            'active_codes' => $active,
            'inactive_codes' => max(0, $codes - $active),
            'total_scans' => $scans,
            'unique_scans' => $unique,

            // ⚠️ R-19 — these are ATTRIBUTED counts and the key names say so.
            // They under-report, because a customer can delete the reference
            // from their own message before sending. The naming is load-bearing:
            // a prop called `customers_messaged` would end up on a card called
            // "Customers Messaged", which R-19 forbids.
            'attributed_messages' => $attributedMessages,
            'attributed_new_contacts' => $attributedContacts,

            // A floor, not a conversion rate. Null rather than 0 when there are
            // no scans — 0% reads as "nobody responded", which is a different
            // claim from "nothing has happened yet".
            'attributed_message_rate' => $scans > 0
                ? round(($attributedMessages / $scans) * 100, 1)
                : null,
        ];
    }

    /**
     * Per-code figures for the My QR Codes table.
     *
     * @return array<int, array{scans: int, attributed_messages: int, last_scan_at: string|null}>
     */
    public function perAssignment(int $workspaceId): array
    {
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
     */
    public function activity(int $workspaceId, int $perPage = 50)
    {
        return $this->access->scanEventsFor($workspaceId)
            ->with(['assignment:id,name,qr_type,smart_qr_code_id', 'assignment.code:id,serial_number'])
            ->orderByDesc('scanned_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * ⚠️ Scans are reached through SmartQrAccess::scanEventsFor(), which joins
     * via the ASSIGNMENT — so a reassigned code's earlier scans belong to the
     * previous tenant's period and are unreachable here. That is R-4 doing its
     * job, and it is why no query in this file filters on workspace directly.
     */
    private function scanQuery(int $workspaceId)
    {
        return $this->access->scanEventsFor($workspaceId)->where('is_bot', false);
    }

    /** @param Collection<int, int> $assignmentIds */
    private function conversions($assignmentIds, string $type): int
    {
        if ($assignmentIds->isEmpty()) {
            return 0;
        }

        return (int) SmartQrConversionEvent::query()
            ->whereIn('smart_qr_assignment_id', $assignmentIds)
            ->where('type', $type)
            ->count();
    }
}
