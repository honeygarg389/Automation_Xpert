<?php

namespace App\Modules\SmartQr\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Scopes\WorkspaceScope;
use App\Modules\SmartQr\Http\Requests\StoreQrBatchRequest;
use App\Modules\SmartQr\Http\Requests\UpdateQrBatchRequest;
use App\Modules\SmartQr\Jobs\GenerateQrBatchJob;
use App\Modules\SmartQr\Models\SmartQrBatch;
use App\Modules\SmartQr\Services\SmartQrDeletability;
use App\Modules\SmartQr\Support\SmartQrStatus;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §4 — QR batches. Super Admin only; a batch never belongs to a tenant.
 */
class QrBatchController extends Controller
{
    public function index(): Response
    {
        $batches = SmartQrBatch::query()
            ->withCount([
                'codes',
                // ⚠️ R-12 — assigned_count is DERIVED, never stored.
                //
                // The spec's §4 field list names it as a batch column. A stored
                // counter must be incremented on assign and decremented on
                // unassign by every path that ever writes an assignment, forever
                // — and drift in a count nobody checks is invisible: the number
                // stays plausible and stops being true.
                //
                // `unassigned_at IS NULL` is the same current-period predicate
                // the gauge and the DB's unique index use.
                'codes as assigned_count' => fn ($q) => $q->whereHas(
                    'currentAssignment',
                    fn ($a) => $a->withoutGlobalScope(WorkspaceScope::class)
                ),
            ])
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/SmartQr/Batches/Index', [
            'batches' => $batches,
        ]);
    }

    public function store(StoreQrBatchRequest $request): RedirectResponse
    {
        $batch = SmartQrBatch::create($request->validated() + [
            'status' => 'draft',
            'created_by_admin_id' => $request->user('admin')?->id,
        ]);

        // Queued (§4): the spec's batches are 500 codes and the action chunks.
        GenerateQrBatchJob::dispatch($batch->id);

        return redirect()
            ->route('admin.qr.batches.show', $batch)
            ->with('success', __('Batch created. Code generation has been queued.'));
    }

    /**
     * ⚠️ RENAME ONLY. batch_name and batch_number, nothing else.
     *
     * `prefix`, `serial_start` and `quantity` are deliberately NOT editable:
     * they define the serial range, the codes are already generated from them,
     * and changing them would leave every existing serial describing a range the
     * batch no longer claims. That is a regeneration, not a rename.
     */
    public function update(UpdateQrBatchRequest $request, SmartQrBatch $batch): RedirectResponse
    {
        $before = $batch->only(['batch_name', 'batch_number']);
        $batch->update($request->validated());

        app(AuditLogService::class)->logAdmin(
            'smart_qr.batch_renamed',
            SmartQrBatch::class,
            $batch->id,
            ['before' => $before, 'after' => $batch->only(['batch_name', 'batch_number'])],
            $request->user('admin'),
        );

        return back()->with('success', __('Batch updated.'));
    }

    /**
     * ⚠️ DELETE — refused unless EVERY code in the batch is deletable.
     *
     * Deleting a batch deletes all of its codes, so one printed sticker among
     * five hundred makes the whole batch retire-only. The refusal NAMES the
     * codes that blocked it: "refused" alone leaves an operator hunting through
     * five hundred rows for the one that matters.
     */
    public function destroy(Request $request, SmartQrBatch $batch, SmartQrDeletability $rule): RedirectResponse
    {
        $blockers = $rule->blockersIn($batch);

        if ($blockers->isNotEmpty()) {
            $shown = $blockers->take(5)
                ->map(fn ($reason, $serial) => "{$serial} ({$reason})")
                ->implode(', ');
            $more = $blockers->count() > 5 ? ' and '.($blockers->count() - 5).' more' : '';

            return back()->withErrors(['batch' => __(
                'This batch cannot be deleted: :count of its codes have been printed or assigned '
                .'(:shown:more). Retire it instead — deleting would destroy assignment history '
                .'and leave printed stickers unexplainable.',
                ['count' => $blockers->count(), 'shown' => $shown, 'more' => $more]
            )]);
        }

        $codeCount = (int) $batch->codes()->count();
        $number = $batch->batch_number;

        // Audit BEFORE the delete: afterwards there is no row to describe.
        app(AuditLogService::class)->logAdmin(
            'smart_qr.batch_deleted',
            SmartQrBatch::class,
            $batch->id,
            ['batch_number' => $number, 'codes_deleted' => $codeCount],
            $request->user('admin'),
        );

        // ⚠️ Codes first. `smart_qr_codes.batch_id` is restrictOnDelete, so the
        // batch cannot be removed while they exist — by design, so a batch can
        // never be orphaned from its codes by accident.
        $batch->codes()->delete();
        $batch->delete();

        return redirect()
            ->route('admin.qr.batches.index')
            ->with('success', __(':count code(s) and their batch were deleted.', ['count' => $codeCount]));
    }

    /**
     * RETIRE — the path that always works.
     *
     * Keeps every row. The codes stay in inventory, cannot be assigned, and
     * (slice 4) resolve to "no longer active" rather than a 404.
     */
    public function retire(Request $request, SmartQrBatch $batch): RedirectResponse
    {
        $affected = $batch->codes()
            ->where('status', '!=', SmartQrStatus::CODE_RETIRED)
            ->update(['status' => SmartQrStatus::CODE_RETIRED]);

        app(AuditLogService::class)->logAdmin(
            'smart_qr.batch_retired',
            SmartQrBatch::class,
            $batch->id,
            ['batch_number' => $batch->batch_number, 'codes_retired' => $affected],
            $request->user('admin'),
        );

        return back()->with('success', __(':count code(s) retired.', ['count' => $affected]));
    }

    public function show(SmartQrBatch $batch): Response
    {
        return Inertia::render('Admin/SmartQr/Batches/Show', [
            'batch' => $batch,
            'codes' => $batch->codes()
                ->with(['currentAssignment' => fn ($q) => $q->withoutGlobalScope(WorkspaceScope::class)])
                ->orderBy('serial_number')
                ->paginate(50),
        ]);
    }
}
