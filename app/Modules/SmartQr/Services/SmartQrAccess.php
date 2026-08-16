<?php

namespace App\Modules\SmartQr\Services;

use App\Models\Scopes\WorkspaceScope;
use App\Modules\SmartQr\Models\SmartQrAssignment;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Models\SmartQrScanEvent;
use Illuminate\Database\Eloquent\Builder;

/**
 * ⚠️ THE ONLY WAY CUSTOMER CODE MAY REACH A SmartQrCode.
 *
 * `SmartQrCode` is lifecycle-owned and carries no `workspace_id`, so it has no
 * global scope and nothing stops a raw query from returning another tenant's
 * codes. The failure would be SILENT — a list that quietly includes rows it
 * should not — which is why this class exists and why
 * `SmartQrAccessGuardTest` fails the build on any `SmartQrCode::` query outside
 * this file and the admin namespace.
 *
 * Every method here joins through `smart_qr_assignments`, which IS
 * workspace-scoped. The tenant boundary is therefore the assignment, applied in
 * one place, rather than a `where` clause each caller must remember.
 */
class SmartQrAccess
{
    /**
     * Codes CURRENTLY assigned to this workspace.
     *
     * `unassigned_at IS NULL` is the current-period filter. Without it a
     * workspace would keep seeing codes it used to hold — the reassignment leak
     * the spec explicitly forbids.
     */
    public function codesFor(int $workspaceId): Builder
    {
        return SmartQrCode::query()->whereHas(
            'currentAssignment',
            fn (Builder $q) => $this->boundedTo($q, $workspaceId)
        );
    }

    /**
     * ⚠️ THE EXPLICIT WORKSPACE IS THE BOUNDARY — and the global scope must be
     * taken off these subqueries, not left on top of it.
     *
     * `SmartQrAssignment` uses `BelongsToWorkspace`, which fails CLOSED. Inside a
     * `whereHas` there is no ambient workspace context, so the scope ANDs
     * `1 = 0` onto the subquery and the join matches NOTHING — every method here
     * returned an empty set for a workspace that genuinely owned codes.
     *
     * Found by the canary, not by reasoning: `an_assigned_code_is_visible_only_to_its_workspace`
     * returned 0 where it should have returned 1. The cross-tenant half of that
     * test passed throughout, because "nobody can see it" satisfies "the other
     * tenant cannot see it" — which is exactly why that test carries a positive
     * control.
     *
     * This is hazard H-2 in its fail-CLOSED direction, the mirror of
     * `UsageMeter::current()` and `ContactCapacity` where the same shape failed
     * open. Both are the same mistake: a scope applied on top of an explicit
     * filter that already IS the boundary.
     */
    private function boundedTo(Builder $query, int $workspaceId): Builder
    {
        return $query
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspaceId);
    }

    /** One code, or null. Never throws — absence and denial look identical. */
    public function findForWorkspace(int $workspaceId, string $serialNumber): ?SmartQrCode
    {
        return $this->codesFor($workspaceId)->where('serial_number', $serialNumber)->first();
    }

    /**
     * The workspace's current assignments.
     *
     * Relies on the model's own workspace scope AND states the filter
     * explicitly. That is redundant by design: if the trait were ever removed
     * this method would still be correct, and the redundancy costs one indexed
     * predicate.
     */
    public function assignmentsFor(int $workspaceId): Builder
    {
        return $this->boundedTo(SmartQrAssignment::query(), $workspaceId)
            ->whereNull('unassigned_at');
    }

    /**
     * EVERY assignment this workspace has ever held — current AND ended.
     *
     * ⚠️ THE COUNTERPART TO assignmentsFor(), AND THE DISTINCTION IS R-4.
     *
     * `assignmentsFor()` answers "what do I hold NOW" and filters
     * `unassigned_at IS NULL`. That is right for counting codes and wrong for
     * counting history: R-4 requires that a reassignment hide the old scans from
     * the NEW tenant while the PREVIOUS tenant keeps its own.
     *
     * Slice 7 introduced this after switching the metrics to aggregates keyed by
     * assignment id. Using the current-only set silently zeroed a former
     * tenant's entire scan history the moment a code was reassigned — caught by
     * slice 6's regression test, not by reasoning.
     *
     * Use this for anything HISTORICAL; use assignmentsFor() for anything
     * describing the present.
     */
    public function allAssignmentsFor(int $workspaceId): Builder
    {
        return $this->boundedTo(SmartQrAssignment::query(), $workspaceId);
    }

    /**
     * Scans visible to this workspace.
     *
     * ⚠️ Scoped through the ASSIGNMENT, so a reassigned code's earlier scans are
     * unreachable — they belong to a different assignment row carrying a
     * different workspace_id. No date arithmetic, and nothing to get wrong when
     * a code changes hands twice in one day.
     */
    public function scanEventsFor(int $workspaceId): Builder
    {
        return SmartQrScanEvent::query()
            ->whereHas(
                'assignment',
                fn (Builder $q) => $this->boundedTo($q, $workspaceId)
            );
    }
}
