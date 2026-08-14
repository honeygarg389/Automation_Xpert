<?php

namespace App\Modules\SmartQr\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\SmartQr\Http\Requests\StoreQrBatchRequest;
use App\Modules\SmartQr\Jobs\GenerateQrBatchJob;
use App\Modules\SmartQr\Models\SmartQrBatch;
use Illuminate\Http\RedirectResponse;
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
                    fn ($a) => $a->withoutGlobalScope(\App\Models\Scopes\WorkspaceScope::class)
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

    public function show(SmartQrBatch $batch): Response
    {
        return Inertia::render('Admin/SmartQr/Batches/Show', [
            'batch' => $batch,
            'codes' => $batch->codes()
                ->with(['currentAssignment' => fn ($q) => $q->withoutGlobalScope(\App\Models\Scopes\WorkspaceScope::class)])
                ->orderBy('serial_number')
                ->paginate(50),
        ]);
    }
}
