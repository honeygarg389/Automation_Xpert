<?php

namespace App\Modules\SmartQr\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\SmartQr\Http\Requests\UpdateCustomerQrRequest;
use App\Modules\SmartQr\Services\SmartQrAccess;
use App\Modules\SmartQr\Services\SmartQrAssignmentValidator;
use App\Modules\SmartQr\Services\SmartQrImageRenderer;
use App\Modules\SmartQr\Services\SmartQrMetrics;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * §11 B — My QR Codes, and the customer's own edits.
 *
 * ─── ⚠️ WRITES ARE ADMINISTRATOR-ONLY, READS ARE NOT ────────────────────────
 *
 * §11 says "allowed customer actions must depend on permissions". **This
 * codebase has no client-side permission system** — there is `client_role`
 * (`administrator` / `staff`) and nothing finer. So the honest reading is
 * client_role: staff can look, administrators can change.
 *
 * Inventing a client permission table for one module would be a pattern with a
 * single caller, which is the cost this project refuses elsewhere (R-9, R-15).
 */
class SmartQrCodeController extends Controller
{
    public function __construct(
        private readonly SmartQrAccess $access,
        private readonly SmartQrMetrics $metrics,
        private readonly SmartQrAssignmentValidator $validator,
        private readonly SmartQrImageRenderer $renderer,
    ) {}

    public function index(Request $request): Response
    {
        $workspaceId = $this->workspaceId($request);
        $workspace = Workspace::findOrFail($workspaceId);

        return Inertia::render('client/SmartQr/Codes', [
            'codes' => $this->access->codesFor($workspaceId)
                ->with([
                    'currentAssignment',
                    'currentAssignment.workspace:id,name',
                ])
                ->orderBy('serial_number')
                ->paginate(25)
                ->withQueryString(),

            'stats' => $this->metrics->perAssignment($workspaceId),
            'canManage' => $this->canManage($request),

            // Pickers for the edit form. Both come from the validator, so the
            // options offered and the rules enforced have ONE definition — a
            // picker built from a different query is how a form offers a choice
            // its own validator rejects.
            'channels' => $this->validator->channelsFor($workspace)
                ->map(fn ($c) => ['id' => $c->id, 'label' => $c->display_name, 'status' => $c->status])
                ->values(),
            'users' => $this->validator->assignableUsersFor($workspace)
                ->map(fn ($u) => ['id' => $u->id, 'label' => $u->name])
                ->values(),
        ]);
    }

    /**
     * ⚠️ Resolved through SmartQrAccess, so a code the customer does not
     * currently hold simply is not found — including one they held LAST month.
     *
     * That is R-4 at the HTTP layer: `codesFor()` joins through
     * `currentAssignment` (`unassigned_at IS NULL`), so a reassigned code
     * disappears from the previous tenant's reach entirely. A 404 here is the
     * correct answer to "edit somebody else's QR", and it is indistinguishable
     * from "no such code" — which is also correct.
     */
    public function update(UpdateCustomerQrRequest $request, string $serial): RedirectResponse
    {
        $workspaceId = $this->workspaceId($request);

        if (! $this->canManage($request)) {
            abort(403);
        }

        $code = $this->access->findForWorkspace($workspaceId, $serial);

        if ($code === null) {
            abort(404);
        }

        $assignment = $code->currentAssignment;

        if ($assignment === null) {
            abort(404);
        }

        $data = $request->validated();
        $workspace = Workspace::findOrFail($workspaceId);

        // ══ ⚠️ THE CROSS-WORKSPACE CHANNEL REFUSAL ═════════════════════════
        //
        // SmartQrAssignmentValidator IN FULL, not partially. The channel must
        // belong to THIS workspace and be active — checked server-side with the
        // scope-bypass shape slice 3a documented, because ChannelAccount is
        // workspace-scoped and a plain query in this context would fail closed
        // and reject every channel including the correct one.
        //
        // Without this, a customer could post another tenant's channel id and
        // point their own QR at somebody else's WhatsApp line.
        if (array_key_exists('channel_account_id', $data) && $data['channel_account_id'] !== null) {
            if (! $this->validator->channelIsAssignable($workspace, (int) $data['channel_account_id'])) {
                return back()->withErrors([
                    'channel_account_id' => __('That WhatsApp channel is not available on this workspace.'),
                ]);
            }
        }

        // ══ ⚠️ R-14 — MEMBERSHIP, not users.workspace_id ═══════════════════
        //
        // Workspace::isAccessibleBy(), the same definition WorkspacePolicy uses.
        // The trap is `$user->workspace_id === $workspace->id`, which reads
        // simpler and silently refuses a legitimate teammate whose PRIMARY
        // workspace is a different one.
        if (array_key_exists('assigned_user_id', $data) && $data['assigned_user_id'] !== null) {
            if (! $this->validator->userBelongsToWorkspace($workspace, (int) $data['assigned_user_id'])) {
                return back()->withErrors([
                    'assigned_user_id' => __('That user is not a member of this workspace.'),
                ]);
            }
        }

        // ═══ ⚠️ THE ADMIN LOCK — THE ONLY TENANT-REACHABLE WRITE TO `status` ═══
        //
        // This route is the single path by which a customer can change their
        // assignment's active/inactive state (UpdateCustomerQrRequest permits
        // `status`, and $assignment->update() writes it), so it is the single
        // place the lock has to hold. The disabled control on Codes.jsx is a
        // courtesy; this is the enforcement, and it is tested with the UI
        // bypassed.
        //
        // ⚠️ GUARDS THE CHANGE, NOT THE REQUEST. A locked tenant may still edit
        // name, qr_type and default_message — the lock is about the toggle and
        // nothing else. Refusing the whole form would take away edits the admin
        // never intended to freeze.
        //
        // ⚠️ COMPARES AGAINST THE CURRENT VALUE, so a form that resubmits an
        // unchanged status (which this one does — it posts every field) is not
        // rejected for changing nothing.
        //
        // ⚠️ withErrors, NOT abort(403). The UI offered this control; a bare 403
        // is indistinguishable from a bug, and the customer would open a ticket
        // asking why the page is broken instead of reading the reason.
        if ($assignment->isLocked()
            && array_key_exists('status', $data)
            && $data['status'] !== $assignment->status) {
            return back()->withErrors([
                'status' => __("This QR's active status is locked by the platform team. Reason: :reason. Contact support to have it unlocked.", [
                    'reason' => $assignment->lock_reason,
                ]),
            ]);
        }

        $assignment->update($data);

        return back()->with('success', __('QR code updated.'));
    }

    /**
     * §11's "QR preview" column — the first of the three holes slice 8 fills.
     *
     * ⚠️ SVG and rendered on demand: crisp at any row height, and it costs no
     * storage. A cached PNG per code would be a storage lifecycle to manage for
     * an operation that takes milliseconds.
     *
     * ⚠️ Resolved through SmartQrAccess, so a code the customer does not
     * currently hold is a 404 — including one they held last month (R-4).
     */
    public function preview(Request $request, string $serial): SymfonyResponse
    {
        $code = $this->access->findForWorkspace($this->workspaceId($request), $serial);

        if ($code === null) {
            abort(404);
        }

        // ⚠️ The batch's logo, not the platform's — and null renders plain.
        // The preview must be the artwork the printer receives, or an admin
        // approves one thing and ships another.
        $rendered = $this->renderer->svg(
            route('smartqr.scan', ['token' => $code->public_token]),
            $code->serial_number,
            $this->renderer->batchLogoPath($code->batch),
        );

        return response($rendered['data'], 200, [
            'Content-Type' => $rendered['mime'],
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * §11's "download digital copy" — the second hole.
     *
     * ⚠️ Generated per request, not stored. A single code is cheap; only bulk
     * needs the queue.
     */
    public function download(Request $request, string $serial): SymfonyResponse
    {
        $code = $this->access->findForWorkspace($this->workspaceId($request), $serial);

        if ($code === null) {
            abort(404);
        }

        $format = $request->query('format', 'svg');

        if (! in_array($format, ['svg', 'png', 'pdf'], true)) {
            abort(422, 'Unsupported format.');
        }

        $url = route('smartqr.scan', ['token' => $code->public_token]);

        $logo = $this->renderer->batchLogoPath($code->batch);

        $rendered = match ($format) {
            'png' => $this->renderer->png($url, $code->serial_number, $logo),
            'pdf' => $this->renderer->pdf($url, $code->serial_number, $logo),
            default => $this->renderer->svg($url, $code->serial_number, $logo),
        };

        return response($rendered['data'], 200, [
            'Content-Type' => $rendered['mime'],
            'Content-Disposition' => 'attachment; filename="'.$code->serial_number.'.'.$format.'"',
        ]);
    }

    /**
     * §11: writes are administrator-only. `client_role` is the only granularity
     * this codebase has — see the class docblock.
     */
    private function canManage(Request $request): bool
    {
        return $request->user()?->client_role === User::CLIENT_ROLE_ADMINISTRATOR;
    }

    private function workspaceId(Request $request): int
    {
        return (int) (WorkspaceContext::id() ?? $request->user()->workspace_id);
    }
}
