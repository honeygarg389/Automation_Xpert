<?php

namespace App\Modules\SmartQr\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Workspace;
use App\Modules\SmartQr\Models\SmartQrBatch;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Services\SmartQrDeletability;
use App\Modules\SmartQr\Support\SmartQrStatus;
use App\Services\AuditLogService;
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

            // ⚠️ ADDED IN SLICE 3b, and it is a gap 3a did not notice.
            //
            // The assignment modal's target picker is a WORKSPACE picker (R-1),
            // and nothing on this page supplied the list — 3a asserted the props
            // it returned rather than the props the screen needed, which is a
            // limitation of testing a controller without its consumer.
            //
            // Client name travels with it because admins navigate by
            // organisation even though the value posted is the workspace id.
            // The label is built here, not in React, so "which client owns this
            // workspace" has one definition.
            //
            // ⚠️ Unpaginated, and that is a known ceiling: at a few thousand
            // workspaces this payload becomes the largest thing on the page and
            // should become a searchable async picker — the same call
            // `assignments.options` already makes per workspace. Left simple
            // deliberately rather than building a search endpoint no installation
            // needs yet.
            'workspaces' => Workspace::query()
                ->with('client:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'client_id'])
                ->map(fn (Workspace $w) => [
                    'id' => $w->id,
                    'name' => $w->name,
                    'client_name' => $w->client?->name,
                ])
                ->values(),
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

    /**
     * ⚠️ DELETE selected codes — refused for any that were printed or assigned.
     *
     * All-or-nothing, matching R-11's shape on the assignment path: a partial
     * delete would report failure while some rows were already gone, and there
     * is no undo for a deleted row.
     */
    public function destroy(Request $request, SmartQrDeletability $rule): RedirectResponse
    {
        $data = $request->validate([
            'code_ids' => ['required', 'array', 'min:1'],
            'code_ids.*' => ['integer', 'exists:smart_qr_codes,id'],
        ]);

        $codes = SmartQrCode::whereIn('id', $data['code_ids'])->get();

        $blocked = $codes
            ->map(fn (SmartQrCode $c) => [$c->serial_number, $rule->blockingReason($c)])
            ->filter(fn ($pair) => $pair[1] !== null);

        if ($blocked->isNotEmpty()) {
            $shown = $blocked->take(5)->map(fn ($p) => "{$p[0]} ({$p[1]})")->implode(', ');
            $more = $blocked->count() > 5 ? ' and '.($blocked->count() - 5).' more' : '';

            return back()->withErrors(['code_ids' => __(
                ':count of the selected codes have been printed or assigned (:shown:more) and '
                .'cannot be deleted. Retire them instead — deleting destroys assignment history '
                .'and leaves printed stickers unexplainable.',
                ['count' => $blocked->count(), 'shown' => $shown, 'more' => $more]
            )]);
        }

        app(AuditLogService::class)->logAdmin(
            'smart_qr.codes_deleted',
            SmartQrCode::class,
            null,
            ['count' => $codes->count(), 'serials' => $codes->pluck('serial_number')->all()],
            $request->user('admin'),
        );

        SmartQrCode::whereIn('id', $data['code_ids'])->delete();

        return back()->with('success', __(':count code(s) deleted.', ['count' => $codes->count()]));
    }
}
