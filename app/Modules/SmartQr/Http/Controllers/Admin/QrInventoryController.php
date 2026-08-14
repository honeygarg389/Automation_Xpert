<?php

namespace App\Modules\SmartQr\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Scopes\WorkspaceScope;
use App\Modules\SmartQr\Models\SmartQrBatch;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Support\SmartQrStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §5 — QR inventory.
 *
 * ⚠️ QUERIES `SmartQrCode` DIRECTLY, and that is legitimate exactly here.
 *
 * The model is lifecycle-owned and carries no workspace scope, so a raw query in
 * customer-facing code would be a silent cross-tenant read — which is why
 * `SmartQrAccessGuardTest` fails the build on one. The admin namespace is
 * allow-listed because unassigned inventory is precisely what this screen exists
 * to manage: a scoped query here would hide every code nobody holds yet.
 *
 * Slice 1 pre-authorised this directory in that guard's ALLOWED list, before the
 * controller existed.
 */
class QrInventoryController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:64'],
            'batch_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(SmartQrStatus::CODE_STATUSES)],
            'assignment' => ['nullable', Rule::in(['assigned', 'unassigned'])],
            'workspace_id' => ['nullable', 'integer'],
            'qr_type' => ['nullable', 'string', 'max:32'],
            'printed' => ['nullable', Rule::in(['yes', 'no'])],
        ]);

        // ⚠️ The scope must come off every assignment subquery below.
        //
        // SmartQrAssignment uses BelongsToWorkspace, and an admin request has no
        // workspace context — so inside a whereHas the scope ANDs 1=0 onto the
        // subquery and it matches NOTHING. Every "assigned" filter would return
        // an empty set for codes that are genuinely assigned.
        //
        // This is the fail-CLOSED direction of hazard H-2, and it is exactly how
        // the slice-1 canary caught SmartQrAccess returning 0.
        $unscoped = fn ($q) => $q->withoutGlobalScope(WorkspaceScope::class);

        $codes = SmartQrCode::query()
            ->with([
                'batch:id,batch_number,batch_name,prefix',
                'currentAssignment' => $unscoped,
                'currentAssignment.workspace:id,name,client_id',
            ])
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where('serial_number', 'like', "%{$v}%"))
            ->when($filters['batch_id'] ?? null, fn ($q, $v) => $q->where('batch_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(($filters['printed'] ?? null) === 'yes', fn ($q) => $q->whereNotNull('printed_at'))
            ->when(($filters['printed'] ?? null) === 'no', fn ($q) => $q->whereNull('printed_at'))
            // ⚠️ R-10 — "assigned" is DERIVED from the current-assignment index,
            // never read from a status column. There is no `assigned` value in
            // SmartQrStatus::CODE_STATUSES to read even if someone tried.
            ->when(($filters['assignment'] ?? null) === 'assigned',
                fn ($q) => $q->whereHas('currentAssignment', $unscoped))
            ->when(($filters['assignment'] ?? null) === 'unassigned',
                fn ($q) => $q->whereDoesntHave('currentAssignment', $unscoped))
            ->when($filters['workspace_id'] ?? null, fn ($q, $v) => $q->whereHas(
                'currentAssignment',
                fn ($a) => $unscoped($a)->where('workspace_id', $v)
            ))
            ->when($filters['qr_type'] ?? null, fn ($q, $v) => $q->whereHas(
                'currentAssignment',
                fn ($a) => $unscoped($a)->where('qr_type', $v)
            ))
            ->orderBy('serial_number')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/SmartQr/Inventory/Index', [
            'codes' => $codes,
            'filters' => $filters,
            'batches' => SmartQrBatch::query()
                ->orderByDesc('id')
                ->get(['id', 'batch_number', 'batch_name']),
            'statuses' => SmartQrStatus::CODE_STATUSES,
        ]);
    }

    /**
     * §5 bulk action — mark printed.
     *
     * ⚠️ A PHYSICAL transition, so it moves `codes.status` (R-10). It says
     * nothing about whether the code is assigned, and must not.
     */
    public function markPrinted(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code_ids' => ['required', 'array', 'min:1'],
            'code_ids.*' => ['integer', 'exists:smart_qr_codes,id'],
        ]);

        SmartQrCode::whereIn('id', $data['code_ids'])
            ->whereIn('status', [SmartQrStatus::CODE_GENERATED, SmartQrStatus::CODE_PRINTED])
            ->update(['status' => SmartQrStatus::CODE_PRINTED, 'printed_at' => now()]);

        return back()->with('success', __('Marked as printed.'));
    }

    /**
     * §5 bulk action — retire / mark damaged / mark lost.
     *
     * Restricted to the physical vocabulary by Rule::in, so no caller can post
     * `assigned` and create the second source of truth R-10 forbids.
     */
    public function changeStatus(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code_ids' => ['required', 'array', 'min:1'],
            'code_ids.*' => ['integer', 'exists:smart_qr_codes,id'],
            'status' => ['required', Rule::in(SmartQrStatus::CODE_STATUSES)],
        ]);

        SmartQrCode::whereIn('id', $data['code_ids'])->update(['status' => $data['status']]);

        return back()->with('success', __('Status updated.'));
    }
}
