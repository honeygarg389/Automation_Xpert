<?php

namespace App\Modules\SmartQr\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Modules\SmartQr\Actions\AssignQrCodesAction;
use App\Modules\SmartQr\Actions\UnassignQrCodeAction;
use App\Modules\SmartQr\Http\Requests\AssignQrCodesRequest;
use App\Modules\SmartQr\Http\Requests\LockQrAssignmentRequest;
use App\Modules\SmartQr\Http\Requests\UpdateQrAssignmentRequest;
use App\Modules\SmartQr\Models\SmartQrAssignment;
use App\Modules\SmartQr\Services\SmartQrAssignmentCapacity;
use App\Modules\SmartQr\Services\SmartQrAssignmentValidator;
use App\Modules\SmartQr\Support\SmartQrStatus;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * §6 — assignment. R-1 (workspace), R-8 (override), R-9 (modal), R-11 (atomic).
 */
class QrAssignmentController extends Controller
{
    public function __construct(
        private readonly SmartQrAssignmentValidator $validator,
        private readonly SmartQrAssignmentCapacity $capacity,
    ) {}

    public function index(Request $request): Response
    {
        // ⚠️ Scope off: an admin listing assignments has no workspace context,
        // and the scope fails closed — this list would be empty for every
        // workspace that actually holds codes.
        $assignments = SmartQrAssignment::withoutWorkspaceScope(
            'reason: the Super Admin assignments screen spans all tenants by design; the '
            .'scope fails closed with no admin workspace context and would show nothing'
        )
            ->with(['code:id,serial_number,status', 'workspace:id,name'])
            ->when($request->query('workspace_id'), fn ($q, $v) => $q->where('workspace_id', $v))
            ->when($request->query('current') !== 'all', fn ($q) => $q->whereNull('unassigned_at'))
            ->latest('assigned_at')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/SmartQr/Assignments/Index', [
            'assignments' => $assignments,
        ]);
    }

    /**
     * The options for one workspace: channels, users, and the capacity numbers.
     *
     * R-9's modal fetches this when a workspace is picked. Ten steps become ten
     * fields of one form — nothing in the described flow requires sequencing.
     */
    public function optionsFor(Workspace $workspace, Request $request): JsonResponse
    {
        return response()->json([
            'channels' => $this->validator->channelsFor($workspace)
                ->map(fn ($c) => ['id' => $c->id, 'label' => $c->display_name, 'status' => $c->status])
                ->values(),
            'users' => $this->validator->assignableUsersFor($workspace)
                ->map(fn ($u) => ['id' => $u->id, 'label' => $u->name.' <'.$u->email.'>'])
                ->values(),
            // So the modal can warn BEFORE submission and show the numbers R-8
            // requires in the refusal.
            'capacity' => $this->capacity->snapshot($workspace, (int) $request->query('requested', 0)),
        ]);
    }

    public function store(AssignQrCodesRequest $request, AssignQrCodesAction $action): RedirectResponse
    {
        $data = $request->validated();
        $workspace = Workspace::findOrFail($data['workspace_id']);
        $admin = $request->user('admin');

        $attributes = [
            'channel_account_id' => $data['channel_account_id'],
            'assigned_user_id' => $data['assigned_user_id'] ?? null,
            'name' => $data['name'] ?? null,
            'qr_type' => $data['qr_type'] ?? null,
            'default_message' => $data['default_message'] ?? null,
            'status' => $data['status'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
        ];

        // ⚠️ R-8 — the override is a SEPARATE PERMISSION from assigning.
        //
        // Checked here rather than on the route, because one endpoint serves
        // both paths and the route middleware cannot see the request body.
        // Folding it into `assign_qr_codes` would mean everyone who can assign
        // can also break the limit, which makes the limit advisory.
        $wantsOverride = (bool) ($data['override_limit'] ?? false);

        if ($wantsOverride && ! $admin?->hasAnyPermission(['override_qr_assignment_limit'])) {
            return back()->withErrors([
                'override_limit' => __('You do not have permission to assign beyond a workspace\'s limit.'),
            ]);
        }

        try {
            $assignments = $wantsOverride
                ? $action->assignOverridingLimit($workspace, $data['code_ids'], $attributes, (string) $data['override_reason'], $admin)
                : $action->assign($workspace, $data['code_ids'], $attributes, $admin);
        } catch (RuntimeException $e) {
            // ⚠️ R-11 — nothing was written. The action refuses before the
            // transaction opens, or the transaction takes the whole batch back
            // out. There is no partial state to report or clean up.
            return back()->withErrors(['code_ids' => $e->getMessage()]);
        }

        return back()->with('success', trans_choice(
            '{1}QR code assigned.|[2,*]:count QR codes assigned.',
            count($assignments),
            ['count' => count($assignments)]
        ));
    }

    /**
     * ⚠️ EDIT lives HERE, on the assignment — not on the code.
     *
     * `name`, `qr_type`, `default_message`, the dates and active/inactive are
     * all columns of `smart_qr_assignments`, because they are PER-TENANT
     * settings: the same physical sticker means "Front counter" to one customer
     * and something else to the next one who holds it. Putting the form on the
     * code would edit a row shared across every tenant that ever held it, and
     * a reassignment would silently inherit the previous tenant's labels.
     *
     * Nothing editable here belongs to `smart_qr_codes` — see
     * UpdateQrAssignmentRequest for what is deliberately absent and why.
     */
    public function update(UpdateQrAssignmentRequest $request, string $uuid): RedirectResponse
    {
        $assignment = SmartQrAssignment::withoutWorkspaceScope(
            'reason: the admin edits assignments across all tenants; a scoped bind would 404 on '
            .'a row that exists and the permission check would never run'
        )->where('uuid', $uuid)->firstOrFail();

        $before = $assignment->only(['name', 'qr_type', 'default_message', 'status', 'starts_at', 'expires_at']);
        $assignment->update($request->validated());

        app(AuditLogService::class)->logAdmin(
            'smart_qr.assignment_updated',
            SmartQrAssignment::class,
            $assignment->id,
            ['workspace_id' => $assignment->workspace_id, 'before' => $before, 'after' => $request->validated()],
            $request->user('admin'),
        );

        return back()->with('success', __('QR details updated.'));
    }

    /**
     * Freeze a tenant out of their own active/inactive toggle. §11.
     *
     * ═══ ⚠️ LOCKING ALSO TURNS THE CODE OFF, IN THE SAME UPDATE ══════════════
     *
     * `admin_locked` and `status` are independent columns, so a lock could in
     * principle freeze a code wherever it happened to be. It does not: the
     * reason an admin reaches for this is that a code must stop serving and stay
     * stopped. Locking an ACTIVE code and leaving it active would satisfy the
     * letter of "the tenant cannot change it" while doing the opposite of what
     * was intended, and nothing on screen would reveal the gap.
     *
     * Both writes happen in ONE update so no observer — the customer's page, the
     * public redirect, another admin — can catch the row locked-but-still-live.
     *
     * ⚠️ forceFill(), NOT update(). The lock columns are deliberately absent from
     * $fillable so the CUSTOMER path cannot mass-assign them; that exclusion
     * would also silently discard them here. See the model.
     *
     * ⚠️ THE PUBLIC REDIRECT IS UNTOUCHED. SmartQrRedirectResolver still routes
     * on `status` alone. The lock adds no outcome and no branch there — a locked
     * code shows the ordinary INACTIVE page, because from a scanner's side that
     * is exactly what it is.
     */
    public function lock(LockQrAssignmentRequest $request, string $uuid): RedirectResponse
    {
        $assignment = SmartQrAssignment::withoutWorkspaceScope(
            'reason: the admin locks assignments across all tenants; a scoped bind would 404 on '
            .'a row that exists and the permission check would never run'
        )->where('uuid', $uuid)->firstOrFail();

        $before = $assignment->only(['status', 'admin_locked', 'lock_reason']);

        $assignment->forceFill([
            'admin_locked' => true,
            'status' => SmartQrStatus::ASSIGNMENT_INACTIVE,
            'lock_reason' => $request->validated('lock_reason'),
            'locked_by_admin_id' => $request->user('admin')?->id,
            'locked_at' => now(),
        ])->save();

        app(AuditLogService::class)->logAdmin(
            'smart_qr.assignment_locked',
            SmartQrAssignment::class,
            $assignment->id,
            [
                'workspace_id' => $assignment->workspace_id,
                'before' => $before,
                'reason' => $request->validated('lock_reason'),
            ],
            $request->user('admin'),
        );

        return back()->with('success', __('QR locked. The customer can no longer change its active status.'));
    }

    /**
     * Hand the toggle back.
     *
     * ⚠️ DOES NOT RE-ACTIVATE. Unlocking returns CONTROL, not state — the tenant
     * chooses whether to switch the code back on. Forcing `active` here would
     * make an admin's administrative act publish a live destination on the
     * customer's behalf, which is their decision and not the platform's.
     *
     * ⚠️ Every lock column is cleared together. Leaving lock_reason behind on an
     * unlocked row would show a stale explanation next to a working control.
     */
    public function unlock(string $uuid, Request $request): RedirectResponse
    {
        $assignment = SmartQrAssignment::withoutWorkspaceScope(
            'reason: the admin unlocks assignments across all tenants; a scoped bind would 404 on '
            .'a row that exists and the permission check would never run'
        )->where('uuid', $uuid)->firstOrFail();

        $before = $assignment->only(['status', 'admin_locked', 'lock_reason']);

        $assignment->forceFill([
            'admin_locked' => false,
            'lock_reason' => null,
            'locked_by_admin_id' => null,
            'locked_at' => null,
        ])->save();

        app(AuditLogService::class)->logAdmin(
            'smart_qr.assignment_unlocked',
            SmartQrAssignment::class,
            $assignment->id,
            ['workspace_id' => $assignment->workspace_id, 'before' => $before],
            $request->user('admin'),
        );

        return back()->with('success', __('QR unlocked. The customer controls its active status again.'));
    }

    public function destroy(string $uuid, UnassignQrCodeAction $action, Request $request): RedirectResponse
    {
        // ⚠️ Resolved by hand rather than by route-model binding. The model is
        // workspace-scoped and this request has no workspace context, so the
        // binding would 404 on a row that exists — authorization never reached,
        // which is the dead-assertion trap CLAUDE.md names.
        $assignment = SmartQrAssignment::withoutWorkspaceScope(
            'reason: the admin unassign screen spans all tenants; scoped binding would 404 on '
            .'a row that exists and the check would never run'
        )->where('uuid', $uuid)->firstOrFail();

        $action->execute($assignment, $request->user('admin'));

        return back()->with('success', __('QR code unassigned. Its scan history stays with the previous tenant.'));
    }
}
