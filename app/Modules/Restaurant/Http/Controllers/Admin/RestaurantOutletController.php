<?php

namespace App\Modules\Restaurant\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Modules\Restaurant\Exceptions\OutletHasActiveConnectionException;
use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Restaurant\Services\RestaurantOutletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 1C, Task B — first-class Outlet Management, independent of whether
 * an outlet is ever connected to Petpooja. "Add Outlet" here creates a row
 * with NO POS connection at all; it becomes usable in that same workspace's
 * "Use existing outlet" dropdown immediately (PosConnectionController::create()).
 *
 * ⚠️ THE OUTLET'S STATUS AND ITS CONNECTION'S STATUS ARE INDEPENDENT AXES.
 * Every combination of {active, archived} outlet status × {not_connected,
 * pending, connected, paused, archived} connection state is valid and must
 * render accurately — an archived outlet's connection does not implicitly
 * become "not connected", and an active outlet can very much have an
 * archived connection sitting on it (that is exactly the state Restore
 * Outlet alone produces — see restoreOutlet()'s own docblock). `paused` is
 * a connection state only; it is never a value RestaurantOutlet::status
 * takes.
 */
class RestaurantOutletController extends Controller
{
    /**
     * `status` tab (Reversible Archive/Restore): defaults to 'active' so an
     * archived outlet does not clutter the day-to-day directory; 'archived'
     * shows only archived outlets, which is where Restore lives.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $workspaceId = $request->query('workspace_id');
        $status = $request->query('status') === RestaurantOutlet::STATUS_ARCHIVED
            ? RestaurantOutlet::STATUS_ARCHIVED
            : RestaurantOutlet::STATUS_ACTIVE;

        // No scope bypass needed: this route runs under auth:admin, and
        // WorkspaceScope's own admin-guard door already opens for any
        // authenticated admin session — this is the platform-wide outlet
        // directory by design, spanning every workspace.
        //
        // ⚠️ THE REAL DEFECT THIS FIXES: `posConnections` used to be eager
        // loaded FILTERED to `status != archived`, so an outlet whose only
        // connection was archived loaded an EMPTY collection here — which
        // is indistinguishable from "never had a connection at all". The
        // outlet's own status (active/archived) and its Petpooja
        // connection's status are two independent axes (see the class
        // docblock's state table); filtering the connection load by status
        // collapsed "archived connection" into "not_connected", which is
        // simply the wrong fact. Loaded unfiltered here — "not connected"
        // must mean zero connection ROWS, never zero NON-ARCHIVED ones.
        $outlets = RestaurantOutlet::query()
            ->with(['workspace:id,name', 'posConnections' => fn ($q) => $q->latest('id')])
            ->where('status', $status)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhereHas('workspace', fn ($w) => $w->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(function (RestaurantOutlet $outlet) {
                // At most one NON-archived connection can exist per outlet
                // (the active_slot invariant) — prefer it when present,
                // since it is the operationally relevant one. Otherwise the
                // outlet's history is entirely archived connections; show
                // the most recent of those rather than "not connected".
                $connection = $outlet->posConnections->first(fn ($c) => $c->status !== PosConnection::STATUS_ARCHIVED)
                    ?? $outlet->posConnections->first();

                return [
                    'uuid' => $outlet->uuid,
                    'workspace_name' => $outlet->workspace?->name,
                    'name' => $outlet->name,
                    'address' => $outlet->address,
                    'timezone' => $outlet->timezone,
                    'status' => $outlet->status,
                    'connection_state' => $connection ? $connection->status : 'not_connected',
                    'connection_uuid' => $connection?->uuid,
                    'digital_bill_enabled' => $outlet->digital_bill_enabled,
                    'feedback_request_enabled' => $outlet->feedback_request_enabled,
                    // Gate 5 of the six-gate live activation invariant — never
                    // required for a sandbox connection, only surfaced here so
                    // an admin can satisfy it ahead of requesting a live one.
                    'authorized_for_live_pos' => $outlet->isAuthorizedForLivePos(),
                ];
            });

        $workspaces = Workspace::query()
            ->with('client:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Workspace $w) => [
                'id' => $w->id,
                'name' => $w->name,
                'client_name' => $w->client?->name,
            ]);

        return Inertia::render('Admin/Restaurant/Outlets/Index', [
            'outlets' => $outlets,
            'workspaces' => $workspaces,
            'filters' => ['search' => $search, 'workspace_id' => $workspaceId, 'status' => $status],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'workspace_id' => ['required', 'integer', 'exists:workspaces,id'],
            'name' => ['required', 'string', 'max:128'],
            'address' => ['nullable', 'string', 'max:512'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);

        $workspace = Workspace::findOrFail($data['workspace_id']);

        $outlet = app(RestaurantOutletService::class)->createOutlet(
            workspace: $workspace,
            name: $data['name'],
            address: $data['address'] ?? null,
            timezone: $data['timezone'] ?? null,
            actor: $request->user('admin'),
        );

        // Section H: "Add Outlet Only" never touches Petpooja — the flash
        // carries just enough to render a "Connect Petpooja now" CTA that
        // preselects this exact workspace/outlet on the New Petpooja
        // Connection page, without redirecting there automatically (the
        // admin may genuinely want to connect it later, or not at all).
        return back()
            ->with('success', 'Outlet added. It is not connected to Petpooja yet.')
            ->with('connectCta', [
                'workspace_id' => $outlet->workspace_id,
                'outlet_id' => $outlet->id,
                'outlet_name' => $outlet->name,
            ]);
    }

    public function update(Request $request, RestaurantOutlet $outlet): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'address' => ['nullable', 'string', 'max:512'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);

        app(RestaurantOutletService::class)->updateOutlet(
            outlet: $outlet,
            name: $data['name'],
            address: $data['address'] ?? null,
            timezone: $data['timezone'] ?? null,
            actor: $request->user('admin'),
        );

        return back()->with('success', 'Outlet updated.');
    }

    /**
     * Phase 2 — deliberately separate from update(): outlet identity and
     * future-message preferences have different product/audit semantics.
     * Saving these booleans never sends anything.
     */
    public function updateMessagingSettings(Request $request, RestaurantOutlet $outlet): RedirectResponse
    {
        $data = $request->validate([
            'digital_bill_enabled' => ['present', 'boolean'],
            'feedback_request_enabled' => ['present', 'boolean'],
        ]);

        app(RestaurantOutletService::class)->updateMessagingSettings(
            outlet: $outlet,
            digitalBillEnabled: (bool) $data['digital_bill_enabled'],
            feedbackRequestEnabled: (bool) $data['feedback_request_enabled'],
            actor: $request->user('admin'),
        );

        return back()->with('success', 'Messaging settings updated. No messages were sent.');
    }

    public function archive(Request $request, RestaurantOutlet $outlet): RedirectResponse
    {
        try {
            app(RestaurantOutletService::class)->archiveOutlet($outlet, $request->user('admin'));
        } catch (OutletHasActiveConnectionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Outlet archived.');
    }

    /**
     * Restores the outlet to active. Deliberately does NOT touch any
     * connection — an archived connection on this outlet stays archived
     * until separately restored and then explicitly resumed.
     */
    public function restore(Request $request, RestaurantOutlet $outlet): RedirectResponse
    {
        try {
            app(RestaurantOutletService::class)->restoreOutlet($outlet, $request->user('admin'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Outlet restored. It is active again. Any archived Petpooja connection stays archived until you restore it separately.');
    }

    /**
     * Gate 5 of the six-gate Petpooja live activation invariant. A real,
     * auditable admin action — the only way `RestaurantOutlet::pos_live_authorized_at`
     * is ever written — not a database-only workaround.
     */
    public function authorizeLivePos(Request $request, RestaurantOutlet $outlet): RedirectResponse
    {
        app(RestaurantOutletService::class)->authorizeForLivePos($outlet, $request->user('admin'));

        return back()->with('success', 'Outlet authorized for a live Petpooja connection.');
    }
}
