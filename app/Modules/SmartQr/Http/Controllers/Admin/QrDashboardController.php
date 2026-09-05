<?php

namespace App\Modules\SmartQr\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Modules\SmartQr\Models\SmartQrAssignment;
use App\Modules\SmartQr\Models\SmartQrBatch;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Models\SmartQrDailyStat;
use App\Modules\SmartQr\Support\SmartQrStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The QR Management overview — stat cards, quick links, two charts, and four
 * list panels. Read-only: this page writes nothing, so unlike the other admin
 * QR controllers it queries `SmartQrCode` and `SmartQrAssignment` directly
 * rather than through an action/service — there is no mutation to route
 * through one for.
 *
 * ⚠️ `SmartQrCode::` direct queries are exempt from `SmartQrAccessGuardTest`
 * in the admin namespace (same exemption `QrInventoryController` and
 * `QrBatchController` already rely on) — this controller lives under
 * `Http/Controllers/Admin`.
 */
class QrDashboardController extends Controller
{
    /**
     * ⚠️ NINE IS DonutChart'S CEILING, not a taste call. Its palette holds nine
     * colours and cycles (`COLORS[index % COLORS.length]`), so a tenth slice
     * repeats the first colour. Eight named workspaces plus "Other" fills it
     * exactly.
     */
    private const DONUT_MAX_SLICES = 9;

    /** Days fetched for the scan chart; the page slices the last 7 from these. */
    private const SCAN_VOLUME_DAYS = 30;

    public function index(): Response
    {
        return Inertia::render('Admin/SmartQr/Dashboard', [
            'stats' => $this->stats(),
            'recentBatches' => $this->recentBatches(),
            'recentAssignments' => $this->recentAssignments(),
            'nearExpiring' => $this->nearExpiring(),
            'recentlyEnded' => $this->recentlyEnded(),
            'lockedCodes' => $this->lockedCodes(),
            'topWorkspaces' => $this->topWorkspaces(),
            'scanVolume' => $this->scanVolume(),
            'recentActivity' => $this->recentActivity(),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function stats(): array
    {
        $totalCodes = SmartQrCode::count();
        $printed = SmartQrCode::where('status', SmartQrStatus::CODE_PRINTED)->count();

        // ⚠️ "Retired" here means status = retired SPECIFICALLY, not the union
        // of the three unassignable physical statuses (retired/lost/damaged).
        // Matches QR Inventory's own Retired filter/badge, which is keyed on
        // this exact status value — see QrStatusBadge's CODE_VARIANTS, which
        // gives `retired`, `lost` and `damaged` three DIFFERENT badge colours
        // (danger/warning/warning) rather than treating them as one bucket.
        // A dashboard "Retired" that silently included lost+damaged would
        // disagree with the Inventory screen's own count for the same word.
        $retired = SmartQrCode::where('status', SmartQrStatus::CODE_RETIRED)->count();

        // Available: exists, not damaged/lost/retired, and not currently held
        // by any tenant. Reuses SmartQrCode::currentAssignment() — already
        // scope-bypassed on the relation itself (see the model) for exactly
        // this cross-tenant "who holds this code" question.
        $available = SmartQrCode::whereDoesntHave('currentAssignment')
            ->whereNotIn('status', SmartQrStatus::CODE_UNASSIGNABLE)
            ->count();

        // Active / Inactive: ASSIGNMENT status, current assignments only
        // (unassigned_at IS NULL) — an ended period must not count either way.
        //
        // ⚠️ withoutWorkspaceScope: an admin dashboard has no ambient workspace,
        // and the scope fails closed — every count below would read zero
        // without this, exactly the H-2 shape documented on
        // QrAssignmentController::index().
        $currentAssignments = SmartQrAssignment::withoutWorkspaceScope(
            'reason: the QR dashboard aggregates platform-wide, all-tenant counts; '
            .'the scope fails closed with no admin workspace context and would report zero'
        )->whereNull('unassigned_at');

        $active = (clone $currentAssignments)->where('status', SmartQrStatus::ASSIGNMENT_ACTIVE)->count();
        $inactive = (clone $currentAssignments)->where('status', SmartQrStatus::ASSIGNMENT_INACTIVE)->count();

        // ⚠️ SANITY CHECK, NOT A GUESS. The schema has a genuine DB-level
        // guarantee — smart_qr_assignments.current_code_id is a VIRTUAL
        // generated column (NULL unless unassigned_at IS NULL) carrying a
        // UNIQUE INDEX (smart_qr_assignments_one_current_per_code) — so two
        // open assignments can never share a code. Checked here rather than
        // trusted blindly: distinct code ids among current assignments must
        // equal the row count, or the counts above are silently wrong.
        $currentCount = (clone $currentAssignments)->count();
        $distinctCodeCount = (clone $currentAssignments)->distinct()->count('smart_qr_code_id');
        if ($currentCount !== $distinctCodeCount) {
            Log::error('smart_qr.dashboard.duplicate_current_assignment', [
                'current_count' => $currentCount,
                'distinct_code_count' => $distinctCodeCount,
            ]);
        }

        // Configured: current, ACTIVE assignments whose channel resolves to a
        // dialable number — the inverse of QrRedirectOutcome::UNCONFIGURED.
        //
        // ⚠️ PURE EXISTS CHECK, NOT A VALUE READ. whereHas() compiles to
        // `WHERE EXISTS (subquery ... WHERE display_phone IS NOT NULL)` — the
        // subquery never selects display_phone into the outer query, so
        // nothing masked by MasksDemoData is ever serialized here. Demo-mode
        // masking applies at toArray() time on a SERIALIZED value; a boolean
        // EXISTS never produces one. If this ever changed to pull the phone
        // value into a select (e.g. for a "which QR is unconfigured" list),
        // that reasoning would need re-checking — it does not apply to a list
        // of raw values, only to a null-check.
        $configured = (clone $currentAssignments)
            ->where('status', SmartQrStatus::ASSIGNMENT_ACTIVE)
            ->whereHas('channelAccount.phoneNumber', fn ($q) => $q->whereNotNull('display_phone'))
            ->count();

        $totalBatches = SmartQrBatch::count();

        return [
            'total_codes' => $totalCodes,
            'available' => $available,
            'active' => $active,
            'total_batches' => $totalBatches,
            'printed' => $printed,
            'configured' => $configured,
            'inactive' => $inactive,
            'retired' => $retired,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentBatches(): array
    {
        // ⚠️ created_at, matching the QR Batches list page's own default sort
        // (QrBatchController::index() orders by created_at desc) — not
        // generated_at, which is null until the queued job finishes and would
        // put a batch still generating at the bottom instead of the top.
        return SmartQrBatch::query()
            ->latest('created_at')
            ->limit(5)
            ->get(['id', 'uuid', 'batch_name', 'batch_number', 'status', 'quantity', 'created_at'])
            ->map(fn (SmartQrBatch $b) => [
                'uuid' => $b->uuid,
                'batch_name' => $b->batch_name,
                'batch_number' => $b->batch_number,
                'status' => $b->status,
                'quantity' => $b->quantity,
                'created_at' => $b->created_at,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentAssignments(): array
    {
        // ⚠️ Recency of assignment only — NOT filtered to "configured" ones.
        // This answers "who most recently received a code", not "who is set
        // up correctly"; conflating the two would hide a just-assigned QR that
        // hasn't been given a channel yet, which is precisely the row an
        // operator would want to see here.
        return SmartQrAssignment::withoutWorkspaceScope(
            'reason: the QR dashboard lists the most recently assigned codes across all '
            .'tenants; the scope fails closed with no admin workspace context'
        )
            ->with(['workspace:id,name', 'code:id,serial_number'])
            ->latest('assigned_at')
            ->limit(5)
            ->get(['id', 'uuid', 'workspace_id', 'smart_qr_code_id', 'name', 'assigned_at'])
            ->map(fn (SmartQrAssignment $a) => [
                'uuid' => $a->uuid,
                'workspace_name' => $a->workspace?->name,
                'serial_number' => $a->code?->serial_number,
                'name' => $a->name,
                'assigned_at' => $a->assigned_at,
            ])
            ->all();
    }

    /**
     * QRs whose period closes within the next 7 days. Soonest first.
     *
     * ⚠️ CURRENT AND ACTIVE ONLY, and both filters earn their place:
     *
     *   unassigned_at IS NULL  an ended period already expired in the sense
     *                          that matters — nobody needs warning about it
     *   status = active        an inactive QR is already not serving, so its
     *                          expiry changes nothing an operator must act on
     *
     * Together they mean every row here is a QR that IS serving today and
     * STOPS serving within the week — which is the only set worth a panel.
     *
     * ⚠️ BETWEEN now() AND +7 days, so an ALREADY-expired assignment is
     * excluded deliberately: `expires_at` in the past is not a warning, it is
     * a state the redirect already reflects (QrRedirectOutcome::EXPIRED).
     * Listing it under "expiring soon" would mix "act now" with "too late".
     *
     * @return list<array<string, mixed>>
     */
    private function nearExpiring(): array
    {
        return SmartQrAssignment::withoutWorkspaceScope(
            'reason: the QR dashboard warns on codes expiring across all tenants; the scope '
            .'fails closed with no admin workspace context and the panel would always be empty'
        )
            ->with(['workspace:id,name', 'code:id,serial_number'])
            ->whereNull('unassigned_at')
            ->where('status', SmartQrStatus::ASSIGNMENT_ACTIVE)
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays(7)])
            ->orderBy('expires_at')
            ->limit(5)
            ->get(['id', 'uuid', 'workspace_id', 'smart_qr_code_id', 'name', 'expires_at'])
            ->map(fn (SmartQrAssignment $a) => [
                'uuid' => $a->uuid,
                'workspace_name' => $a->workspace?->name,
                'serial_number' => $a->code?->serial_number,
                'name' => $a->name,
                'expires_at' => $a->expires_at,
            ])
            ->all();
    }

    /**
     * How many QRs an admin has frozen the tenant out of, right now.
     *
     * ⚠️ BOTH CONDITIONS ARE REQUIRED, and the second is the non-obvious one.
     * `UnassignQrCodeAction` writes ONLY `unassigned_at` and `status` — it does
     * NOT clear `admin_locked`, `lock_reason`, `locked_by_admin_id` or
     * `locked_at`. So locking a QR and later unassigning it leaves a row with
     * `admin_locked = true` AND a closed period, and counting the flag alone
     * would report a lock that no longer restrains anybody. `admin_locked` is
     * indexed (smart_qr_assignments_admin_locked_index).
     */
    private function lockedCodes(): int
    {
        return SmartQrAssignment::withoutWorkspaceScope(
            'reason: the QR dashboard counts locked codes across all tenants; the scope fails '
            .'closed with no admin workspace context and would report zero'
        )
            ->where('admin_locked', true)
            ->whereNull('unassigned_at')
            ->count();
    }

    /**
     * The last five periods to close. Newest first.
     *
     * ⚠️ `held_days` IS AN INT, NOT A PRE-RENDERED STRING. Every other label on
     * this page comes from en.json, and a duration composed server-side ("3
     * days") would be the one piece of English the frontend could not
     * translate. The 0 case — a period opened and closed inside one day, which
     * really happens (measured in dev) — is rendered as "less than a day" by
     * the component rather than a bare "0d", which reads as a bug.
     *
     * ⚠️ THE SAME SERIAL CAN APPEAR TWICE. A code is reassigned by closing one
     * period and opening another (R-4), so two ended rows for one code are
     * correct, not duplicates — the workspace column is what tells them apart.
     *
     * @return list<array<string, mixed>>
     */
    private function recentlyEnded(): array
    {
        return SmartQrAssignment::withoutWorkspaceScope(
            'reason: the QR dashboard lists recently ended periods across all tenants; the '
            .'scope fails closed with no admin workspace context'
        )
            ->with(['workspace:id,name', 'code:id,serial_number'])
            ->whereNotNull('unassigned_at')
            ->latest('unassigned_at')
            ->limit(5)
            ->get(['id', 'uuid', 'workspace_id', 'smart_qr_code_id', 'name', 'assigned_at', 'unassigned_at'])
            ->map(fn (SmartQrAssignment $a) => [
                'uuid' => $a->uuid,
                'workspace_name' => $a->workspace?->name,
                'serial_number' => $a->code?->serial_number,
                'assigned_at' => $a->assigned_at,
                'unassigned_at' => $a->unassigned_at,
                'held_days' => $a->assigned_at && $a->unassigned_at
                    ? (int) abs($a->assigned_at->diffInDays($a->unassigned_at))
                    : null,
            ])
            ->all();
    }

    /**
     * Which tenants hold the most codes RIGHT NOW. Donut data.
     *
     * ⚠️ CURRENT HOLDINGS, NOT LIFETIME USAGE — and the distinction is the
     * whole point of the label. `whereNull('unassigned_at')` means a workspace
     * that churned fifty codes and holds none today contributes ZERO here. The
     * alternative (COUNT(DISTINCT code) over all periods) answers "who has used
     * us most", which is a different question and would let a tenant holding
     * nothing dominate a chart about inventory. The title says "Currently
     * Held" for exactly this reason.
     *
     * ⚠️ CAPPED AT 8 SLICES PLUS "Other", because DonutChart's palette holds
     * NINE colours and cycles with `COLORS[index % COLORS.length]` — a tenth
     * workspace would silently reuse the first colour, putting two identically
     * coloured slices in one chart. Its label renderer also drops any slice
     * under 4%, so a long tail arrives unlabelled anyway. Nine is therefore the
     * honest maximum this component can draw, not an arbitrary cut-off.
     *
     * @return list<array{name: string, value: int}>
     */
    private function topWorkspaces(): array
    {
        $rows = SmartQrAssignment::withoutWorkspaceScope(
            'reason: the QR dashboard ranks tenants by current holdings platform-wide; the '
            .'scope fails closed with no admin workspace context'
        )
            ->whereNull('unassigned_at')
            ->select('workspace_id', DB::raw('count(*) as cnt'))
            ->groupBy('workspace_id')
            ->with('workspace:id,name')
            ->orderByDesc('cnt')
            ->get();

        $named = [];

        foreach ($rows as $row) {
            $named[] = [
                'name' => $row->workspace?->name,
                // ⚠️ Read out of the attribute BAG, not as `$row->cnt`. `cnt` is
                // a select alias, not a column, so it is not a declared property
                // and a property read is untypeable — which static analysis is
                // right to reject rather than us silencing.
                'value' => (int) $row->getAttributes()['cnt'],
                'is_other' => false,
            ];
        }

        if (count($named) <= self::DONUT_MAX_SLICES) {
            return $named;
        }

        // ⚠️ FLAGGED, NOT NAMED "Other" HERE. Workspace names are tenant data
        // and pass through untranslated, but the bucket label is UI copy — a
        // literal 'Other' from the controller would be the one string on this
        // page that no locale file could reach. The page renders the flag.
        $top = array_slice($named, 0, self::DONUT_MAX_SLICES - 1);
        $rest = array_slice($named, self::DONUT_MAX_SLICES - 1);

        $top[] = [
            'name' => null,
            'value' => (int) array_sum(array_column($rest, 'value')),
            'is_other' => true,
        ];

        return $top;
    }

    /**
     * Platform-wide scan volume, one row per day, ENDING YESTERDAY.
     *
     * ⚠️ TODAY IS DELIBERATELY EXCLUDED. `smartqr:aggregate` builds back from
     * yesterday and never writes a row for the current day, so including today
     * would append a guaranteed zero to every series, every morning — which
     * reads as traffic collapsing rather than as a day not yet aggregated.
     * This is the same window SmartQrMetrics::report() already defaults to, and
     * the reasoning is recorded there too.
     *
     * ⚠️ NO SCOPE BYPASS, and that is not an oversight: SmartQrDailyStat has no
     * workspace_id and does not use BelongsToWorkspace — the grain is the
     * assignment, whose tenant is fixed for the life of the row. So a
     * platform-wide rollup is a plain GROUP BY with no join and nothing to
     * bypass. The index is (stat_date, smart_qr_assignment_id), leading on the
     * column this groups by.
     *
     * ⚠️ ZERO-FILLED across the whole window, replicating
     * SmartQrMetrics::trend()'s loop (private there, so not callable). A gap
     * left as a missing point makes a line chart draw straight through it and
     * imply activity that did not happen.
     *
     * Returns the full 30 days; the page slices the last 7 client-side rather
     * than making a second round trip for a subset it already holds.
     *
     * @return list<array{date: string, scans: int, unique_scans: int}>
     */
    private function scanVolume(): array
    {
        $to = now()->startOfDay()->subDay();
        $from = $to->copy()->subDays(self::SCAN_VOLUME_DAYS - 1);

        $byDate = SmartQrDailyStat::query()
            ->selectRaw('stat_date, SUM(scans) as scans, SUM(unique_scans) as unique_scans')
            ->whereBetween('stat_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('stat_date')
            ->get()
            ->keyBy(fn (SmartQrDailyStat $r) => $r->stat_date->toDateString());

        $out = [];
        $cursor = $from->copy();

        while ($cursor->lte($to)) {
            $key = $cursor->toDateString();
            $day = $byDate->get($key);

            $out[] = [
                'date' => $key,
                'scans' => (int) ($day->scans ?? 0),
                'unique_scans' => (int) ($day->unique_scans ?? 0),
            ];

            $cursor->addDay();
        }

        return $out;
    }

    /**
     * ⚠️ ADMIN NAMES ARE JOINED BY HAND, NOT VIA THE `actorAdmin` RELATION.
     *
     * `AuditLog` carries no `@property`/`@return` generics on any of its
     * relations (pre-existing, whole-model gap — not something this
     * controller's scope should fix), so `$log->actorAdmin?->name` resolves to
     * an untyped `Model` and PHPStan flags `$name` as undefined. `pluck()`
     * takes a column name as a plain string, not a typed property access, so
     * it sidesteps the gap instead of asserting past it.
     *
     * @return list<array<string, mixed>>
     */
    private function recentActivity(): array
    {
        $logs = AuditLog::query()
            ->where('action', 'like', 'smart_qr.%')
            ->latest('created_at')
            ->limit(10)
            ->get(['id', 'action', 'actor_admin_id', 'meta', 'created_at']);

        $adminNames = AdminUser::query()
            ->whereIn('id', $logs->pluck('actor_admin_id')->filter())
            ->pluck('name', 'id');

        return $logs->map(fn (AuditLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'actor_name' => $log->actor_admin_id ? ($adminNames->get($log->actor_admin_id)) : null,
            'meta' => $log->meta,
            'created_at' => $log->created_at,
        ])->all();
    }
}
