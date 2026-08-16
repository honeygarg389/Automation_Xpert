<?php

namespace App\Modules\SmartQr\Services;

use App\Models\Scopes\WorkspaceScope;
use App\Modules\SmartQr\Models\SmartQrBatch;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Support\SmartQrStatus;
use Illuminate\Support\Collection;

/**
 * ⚠️ WHEN A QR CODE MAY BE DELETED, AND WHEN IT MAY ONLY BE RETIRED.
 *
 * ─── THE RULE ───────────────────────────────────────────────────────────────
 *
 * A code that has NEVER been printed and has NEVER been assigned — now or at any
 * point in the past — can be genuinely deleted. Anything else can only be
 * RETIRED.
 *
 * ─── WHY, BECAUSE THIS IS NOT AN ORDINARY SOFT-DELETE PREFERENCE ────────────
 *
 * **These rows describe physical objects.** Deleting a printed code's row does
 * not remove the sticker from the counter — it removes our ability to know the
 * sticker ever existed. A later scan of it then resolves to nothing, and the
 * operator has an unexplainable request rather than a retired code.
 *
 * **And deleting an assigned code destroys its assignment history**, which
 * destroys the previous tenant's scan data with it — scans are keyed by
 * `smart_qr_assignment_id` and the assignment cascades on delete. That is
 * precisely what R-4 and the whole reassignment model exist to preserve.
 *
 * Retiring keeps the row: it stays in inventory, cannot be assigned, and (slice
 * 4) resolves to "this QR is no longer active" rather than a 404.
 *
 * ─── ⚠️ "NEVER ASSIGNED" MEANS EVER, NOT CURRENTLY ──────────────────────────
 *
 * The check is `assignments()`, not `currentAssignment()`. A code whose only
 * assignment ended last month still carries that tenant's period and their
 * scans. Asking the "current" question would report it deletable and take the
 * history with it — the exact failure this class exists to prevent.
 */
class SmartQrDeletability
{
    /**
     * Has this code ever been printed?
     *
     * Both signals are checked, not one. `markPrinted` sets `printed_at` AND
     * `status`, but a seeder, an import or a hand-written UPDATE may set either
     * alone — and this decides whether a physical object is denied or destroyed,
     * which is not a place to trust one column.
     */
    public function everPrinted(SmartQrCode $code): bool
    {
        return $code->printed_at !== null
            || $code->status === SmartQrStatus::CODE_PRINTED;
    }

    /**
     * Has this code ever been assigned?
     *
     * ⚠️ Scope dropped deliberately. `SmartQrAssignment` is workspace-scoped and
     * an admin request carries no workspace, so a scoped count returns ZERO for
     * every code — which would report every assigned code as deletable and
     * destroy tenant history. Fail-CLOSED turning into fail-DESTRUCTIVE.
     */
    public function everAssigned(SmartQrCode $code): bool
    {
        return $code->assignments()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->exists();
    }

    public function codeIsDeletable(SmartQrCode $code): bool
    {
        return ! $this->everPrinted($code) && ! $this->everAssigned($code);
    }

    /**
     * Why a code cannot be deleted, or null if it can.
     *
     * Returned as a reason rather than a boolean so the refusal can name what
     * blocked it — "refused" alone leaves an operator guessing which of two
     * rules they hit.
     */
    public function blockingReason(SmartQrCode $code): ?string
    {
        if ($this->everPrinted($code)) {
            return 'printed';
        }

        if ($this->everAssigned($code)) {
            return 'assigned';
        }

        return null;
    }

    /**
     * The codes in a batch that block its deletion, keyed by serial => reason.
     *
     * ⚠️ ONE QUERY, not N. A 500-code batch would otherwise issue 1,000 queries
     * to answer a question the database can answer with two joins.
     *
     * @return Collection<string, string>
     */
    public function blockersIn(SmartQrBatch $batch): Collection
    {
        return SmartQrCode::query()
            ->where('batch_id', $batch->id)
            ->where(function ($q) {
                $q->whereNotNull('printed_at')
                    ->orWhere('status', SmartQrStatus::CODE_PRINTED)
                    ->orWhereHas('assignments', fn ($a) => $a->withoutGlobalScope(WorkspaceScope::class));
            })
            ->orderBy('serial_number')
            ->get()
            ->mapWithKeys(fn (SmartQrCode $c) => [$c->serial_number => $this->blockingReason($c) ?? 'unknown']);
    }

    /**
     * ⚠️ A batch is deletable only when EVERY one of its codes is.
     *
     * Deleting a batch deletes all of its codes, so one printed sticker among
     * five hundred makes the whole batch retire-only. That is deliberately
     * strict: the alternative is a partial delete, which leaves a batch whose
     * `quantity` no longer describes anything.
     */
    public function batchIsDeletable(SmartQrBatch $batch): bool
    {
        return $this->blockersIn($batch)->isEmpty();
    }
}
