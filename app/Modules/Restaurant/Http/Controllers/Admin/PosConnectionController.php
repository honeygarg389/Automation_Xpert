<?php

namespace App\Modules\Restaurant\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Modules\Restaurant\Exceptions\ConnectionHasHistoryException;
use App\Modules\Restaurant\Exceptions\ConnectionNotMovableException;
use App\Modules\Restaurant\Exceptions\OutletAlreadyConnectedException;
use App\Modules\Restaurant\Http\Requests\StorePosConnectionRequest;
use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\PosWebhookEvent;
use App\Modules\Restaurant\Models\PosWebhookRejection;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Restaurant\Services\PosConnectionProvisioningService;
use App\Modules\Restaurant\Services\RestaurantOutletService;
use App\Modules\Restaurant\Support\IpAllowlistNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 1C — Super Admin management of Restaurant/POS connections.
 *
 * Every mutating action delegates to PosConnectionProvisioningService, which
 * owns the audit trail and the token-secrecy guarantees; this controller's
 * job is request handling and choosing what to show, never generating or
 * touching a plaintext token itself.
 *
 * Sandbox-only, by construction, not merely by convention: nothing here
 * accepts an `environment` value from the request at all — every create
 * goes through createSandboxConnection(), which hardcodes
 * PosConnection::ENVIRONMENT_SANDBOX.
 */
class PosConnectionController extends Controller
{
    /**
     * `status` tab (Section: Reversible Archive/Restore): 'active' (default)
     * shows every non-archived connection — pending/connected/paused — and
     * 'archived' shows only archived ones, so an archived connection stays
     * visible and reachable (for Restore) rather than silently disappearing
     * once archived. Named 'active' to mirror the Outlets directory's own
     * Active/Archived tabs, not to be confused with the per-row status
     * badge (Pending/Connected/Paused/Archived).
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status') === 'archived' ? 'archived' : 'active';

        $connections = PosConnection::query()
            ->with(['workspace:id,name', 'outlet:id,name'])
            ->when($status === 'archived',
                fn ($query) => $query->where('status', PosConnection::STATUS_ARCHIVED),
                fn ($query) => $query->where('status', '!=', PosConnection::STATUS_ARCHIVED),
            )
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('external_ref', 'like', "%{$search}%")
                        ->orWhereHas('workspace', fn ($w) => $w->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('outlet', fn ($o) => $o->where('name', 'like', "%{$search}%"));
                });
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (PosConnection $c) => [
                'uuid' => $c->uuid,
                'workspace_name' => $c->workspace?->name,
                'outlet_name' => $c->outlet?->name,
                'provider' => $c->provider,
                'external_ref' => $c->external_ref,
                'environment' => $c->environment,
                'status' => $c->status,
                'token_configured' => $c->webhook_secret_hash !== null,
                'last_event_at' => $c->last_event_at?->toIso8601String(),
                'last_test_status' => $c->last_test_status,
            ]);

        return Inertia::render('Admin/Restaurant/Connections/Index', [
            'connections' => $connections,
            'filters' => ['search' => $search, 'status' => $status],
        ]);
    }

    /**
     * `workspace_id`/`outlet_id` query params are an OPTIONAL preselect —
     * populated when arriving via the Outlets directory's "Connect Petpooja
     * now" CTA (Section H) — never trusted as-is: the frontend only actually
     * preselects the outlet if it still appears in `outlets` as eligible
     * (active, no non-archived connection), and `store()` re-validates
     * everything regardless of what this pre-fills.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Admin/Restaurant/Connections/Create', [
            'workspaces' => $this->workspaceOptions(),
            'outlets' => $this->outletOptionsWithConnectionState(),
            'preselect' => [
                'workspace_id' => $request->integer('workspace_id') ?: null,
                'outlet_id' => $request->integer('outlet_id') ?: null,
            ],
        ]);
    }

    /**
     * ⚠️ THE ROOT-CAUSE FIX. This trusts ONLY the explicit `mode` field to
     * decide which branch runs — never `isset($data['outlet_id'])`, which is
     * exactly what silently accepted a stale outlet_id in 'new' mode before
     * (see StorePosConnectionRequest's docblock for the measured defect).
     * The OTHER mode's fields are never read at all in either branch,
     * regardless of what they contain.
     */
    public function store(StorePosConnectionRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if ($data['mode'] === 'existing') {
            $outlet = RestaurantOutlet::findOrFail($data['outlet_id']);
        } else {
            $workspace = Workspace::findOrFail($data['workspace_id']);
            $outlet = app(RestaurantOutletService::class)->createOutlet(
                workspace: $workspace,
                name: $data['new_outlet_name'],
                address: $data['new_outlet_address'] ?? null,
                timezone: $data['new_outlet_timezone'] ?? null,
                actor: $request->user('admin'),
            );
        }

        try {
            $connection = app(PosConnectionProvisioningService::class)->createSandboxConnection(
                outlet: $outlet,
                externalRef: $data['external_ref'],
                allowedIps: $data['allowed_ips'] ?? null,
                actor: $request->user('admin'),
            );
        } catch (OutletAlreadyConnectedException $e) {
            // The DB-level backstop caught a race the validation-time check
            // missed (a second admin, or a repeated/UI-bypassed request) —
            // still a graceful redirect, never a raw exception page.
            return back()->withErrors(['outlet_id' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.restaurant.connections.show', $connection)
            ->with('success', 'Sandbox connection created. Generate a webhook token to finish setup.');
    }

    public function show(PosConnection $connection): Response
    {
        // ⚠️ THE REAL BUG BEHIND "Restore Connection never appears": this
        // partial-column eager load omitted `status`, so `is_restorable`'s
        // `$connection->outlet->status === RestaurantOutlet::STATUS_ACTIVE`
        // check ALWAYS evaluated false — the outlet attribute was simply
        // never loaded (null, not the real value) — for every connection on
        // this page, not just archived ones. `status` must be selected
        // whenever outlet->status is read anywhere downstream.
        $connection->load(['workspace:id,name', 'outlet:id,name,address,timezone,status']);

        $recentEvents = PosWebhookEvent::query()
            ->where('connection_id', $connection->id)
            ->latest('received_at')
            ->limit(20)
            ->get(['id', 'event_type', 'processing_status', 'received_at', 'failure_reason'])
            ->map(fn (PosWebhookEvent $e) => [
                'id' => $e->id,
                'event_type' => $e->event_type,
                'processing_status' => $e->processing_status,
                'received_at' => $e->received_at->toIso8601String(),
                'failure_reason' => $e->failure_reason,
            ]);

        $recentRejections = PosWebhookRejection::query()
            ->where('connection_id', $connection->id)
            ->latest('received_at')
            ->limit(20)
            ->get(['id', 'failure_reason', 'source_ip', 'received_at'])
            ->map(fn (PosWebhookRejection $r) => [
                'id' => $r->id,
                'failure_reason' => $r->failure_reason,
                'source_ip' => $r->source_ip,
                'received_at' => $r->received_at->toIso8601String(),
            ]);

        $hasHistory = $connection->hasWebhookHistory();

        return Inertia::render('Admin/Restaurant/Connections/Show', [
            'connection' => [
                'uuid' => $connection->uuid,
                'workspace_id' => $connection->workspace_id,
                'workspace_name' => $connection->workspace?->name,
                'outlet_id' => $connection->outlet_id,
                'outlet' => $connection->outlet ? [
                    'name' => $connection->outlet->name,
                    'address' => $connection->outlet->address,
                    'timezone' => $connection->outlet->timezone,
                ] : null,
                'provider' => $connection->provider,
                'environment' => $connection->environment,
                'external_ref' => $connection->external_ref,
                'status' => $connection->status,
                'token_configured' => $connection->webhook_secret_hash !== null,
                'webhook_secret_rotated_at' => $connection->webhook_secret_rotated_at?->toIso8601String(),
                'allowed_ips' => $connection->allowed_ips,
                'last_event_at' => $connection->last_event_at?->toIso8601String(),
                'last_tested_at' => $connection->last_tested_at?->toIso8601String(),
                'last_test_status' => $connection->last_test_status,
                'last_test_message' => $connection->last_test_message,
                'has_webhook_history' => $hasHistory,
                'is_deletable' => $connection->environment === PosConnection::ENVIRONMENT_SANDBOX && ! $hasHistory,
                'is_movable' => $connection->environment === PosConnection::ENVIRONMENT_SANDBOX && ! $hasHistory,
                // Mirrors restoreConnection()'s own guards exactly, purely
                // for button visibility — the service re-checks all of this
                // independently regardless of what this flag says.
                'is_restorable' => $connection->status === PosConnection::STATUS_ARCHIVED
                    && $connection->outlet !== null
                    && $connection->outlet->status === RestaurantOutlet::STATUS_ACTIVE
                    && ! $connection->outlet->hasNonArchivedConnection(),
                // No raw_body, no raw_payload, no customer/bill fields, no
                // webhook_secret_hash, no plaintext token — this array is
                // hand-built rather than a model dump specifically so a
                // future column can never leak here silently.
            ],
            'recentEvents' => $recentEvents,
            'recentRejections' => $recentRejections,
            'webhookUrl' => url('/webhooks/pos/petpooja'),
            // For the guarded move flow's target picker — same shape as create().
            'workspaces' => $this->workspaceOptions(),
            'outlets' => $this->outletOptionsWithConnectionState(),
        ]);
    }

    /**
     * The ONE JSON endpoint that ever returns a plaintext token. Generates
     * if none exists yet, rotates if one does — mechanically identical
     * either way (see the service); the UI-facing distinction ("Generate"
     * vs "Rotate") is purely which button was showing.
     *
     * `Cache-Control: no-store`: this response must never be served from a
     * browser back/forward cache or an intermediary — the whole point of
     * "shown once" is undermined if a cached copy of this exact response
     * could be replayed.
     */
    public function generateToken(Request $request, PosConnection $connection): JsonResponse
    {
        $service = app(PosConnectionProvisioningService::class);

        $token = $connection->webhook_secret_hash === null
            ? $service->generateToken($connection, $request->user('admin'))
            : $service->rotateToken($connection, $request->user('admin'));

        return response()->json([
            'token' => $token,
            'rotated_at' => $connection->fresh()->webhook_secret_rotated_at?->toIso8601String(),
        ])->header('Cache-Control', 'no-store');
    }

    public function updateAllowedIps(Request $request, PosConnection $connection): RedirectResponse
    {
        // Same normalization as StorePosConnectionRequest — trims, drops
        // blank entries, deduplicates — before the `ip` rule ever sees them.
        $request->merge(['allowed_ips' => IpAllowlistNormalizer::normalize($request->input('allowed_ips'))]);

        $validated = $request->validate([
            'allowed_ips' => ['nullable', 'array'],
            'allowed_ips.*' => ['string', 'ip'],
        ], [
            'allowed_ips.*.ip' => 'Each allowed IP must be a valid IP address. Check for typos and remove anything that is not a plain IP (CIDR ranges are not supported).',
        ]);

        app(PosConnectionProvisioningService::class)->updateAllowedIps(
            $connection,
            $validated['allowed_ips'] ?? null,
            $request->user('admin'),
        );

        return back()->with('success', 'IP allowlist updated.');
    }

    /** "Activate" (from pending) and "Resume" (from paused) are the same action. */
    public function activate(Request $request, PosConnection $connection): RedirectResponse
    {
        try {
            app(PosConnectionProvisioningService::class)->activateSandbox($connection, $request->user('admin'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Sandbox ingress activated. Petpooja sandbox/test deliveries can now reach this connection.');
    }

    public function pause(Request $request, PosConnection $connection): RedirectResponse
    {
        try {
            app(PosConnectionProvisioningService::class)->pauseConnection($connection, $request->user('admin'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Connection paused. Ingress will reject new deliveries until it is resumed.');
    }

    public function archive(Request $request, PosConnection $connection): RedirectResponse
    {
        app(PosConnectionProvisioningService::class)->archiveConnection($connection, $request->user('admin'));

        return back()->with('success', 'Connection archived. Records and webhook history are retained; ingress is blocked until restored.');
    }

    /**
     * Restores an archived connection back to PAUSED — never straight to
     * CONNECTED. Resuming ingress from there is the existing "Activate/
     * Resume" action (activate()), a deliberately separate, explicit step.
     */
    public function restore(Request $request, PosConnection $connection): RedirectResponse
    {
        try {
            app(PosConnectionProvisioningService::class)->restoreConnection($connection, $request->user('admin'));
        } catch (OutletAlreadyConnectedException|\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Connection restored as Paused. Ingress stays blocked until you click Resume. If it was archived for a security concern, rotate the token before resuming.');
    }

    public function destroy(Request $request, PosConnection $connection): RedirectResponse
    {
        try {
            app(PosConnectionProvisioningService::class)->deleteTestConnection($connection, $request->user('admin'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.restaurant.connections.index')
            ->with('success', 'Test connection deleted.');
    }

    /**
     * The guarded Super Admin correction flow (Task E). Accepts EITHER an
     * existing eligible target outlet_id, or new-outlet fields to create one
     * in the destination workspace first — same "explicit mode" shape as
     * StorePosConnectionRequest, for the same reason.
     */
    public function move(Request $request, PosConnection $connection): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:existing,new'],
            'target_workspace_id' => ['required', 'integer', 'exists:workspaces,id'],
            'target_outlet_id' => ['required_if:mode,existing', 'nullable', 'integer', 'exists:restaurant_outlets,id'],
            'new_outlet_name' => ['required_if:mode,new', 'nullable', 'string', 'max:128'],
            'new_outlet_address' => ['nullable', 'string', 'max:512'],
            'new_outlet_timezone' => ['nullable', 'string', 'max:64'],
            'confirmed' => ['required', 'accepted'],
        ], [
            'confirmed.accepted' => 'You must explicitly confirm the move.',
        ]);

        if ($data['mode'] === 'existing') {
            $targetOutlet = RestaurantOutlet::findOrFail($data['target_outlet_id']);
        } else {
            $targetWorkspace = Workspace::findOrFail($data['target_workspace_id']);
            $targetOutlet = app(RestaurantOutletService::class)->createOutlet(
                workspace: $targetWorkspace,
                name: $data['new_outlet_name'],
                address: $data['new_outlet_address'] ?? null,
                timezone: $data['new_outlet_timezone'] ?? null,
                actor: $request->user('admin'),
            );
        }

        try {
            [$moved] = app(PosConnectionProvisioningService::class)->moveConnection(
                $connection,
                $targetOutlet,
                $request->user('admin'),
            );
        } catch (ConnectionHasHistoryException|ConnectionNotMovableException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.restaurant.connections.show', $moved)
            ->with('success', 'Connection moved. Its token was rotated — generate and share the new one before resuming ingress.');
    }

    /** @return list<array{id: int, name: string, client_name: ?string}> */
    private function workspaceOptions(): array
    {
        return Workspace::query()
            ->with('client:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Workspace $w) => [
                'id' => $w->id,
                'name' => $w->name,
                'client_name' => $w->client?->name,
            ])
            ->all();
    }

    /**
     * Every outlet, annotated with its current non-archived connection (if
     * any). The frontend derives "eligible for a new connection" as
     * `status === 'active' && connection === null` for the dropdown, and
     * lists everything else in the "already connected" helper (Task C).
     *
     * @return list<array{id: int, workspace_id: int, name: string, status: string, connection: array{uuid: string, status: string}|null}>
     */
    private function outletOptionsWithConnectionState(): array
    {
        return RestaurantOutlet::query()
            ->with(['posConnections' => fn ($q) => $q->where('status', '!=', PosConnection::STATUS_ARCHIVED)])
            ->orderBy('name')
            ->get(['id', 'workspace_id', 'name', 'status'])
            ->map(function (RestaurantOutlet $o) {
                $connection = $o->posConnections->first();

                return [
                    'id' => $o->id,
                    'workspace_id' => $o->workspace_id,
                    'name' => $o->name,
                    'status' => $o->status,
                    'connection' => $connection ? [
                        'uuid' => $connection->uuid,
                        'status' => $connection->status,
                    ] : null,
                ];
            })
            ->all();
    }
}
