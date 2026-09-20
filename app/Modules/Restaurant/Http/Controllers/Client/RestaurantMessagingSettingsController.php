<?php

namespace App\Modules\Restaurant\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Restaurant\Services\RestaurantOutletService;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workspace-owner settings surface only. It intentionally exposes neither
 * outlet provisioning nor POS connection/token functionality.
 */
class RestaurantMessagingSettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $this->ensureWorkspaceAdministrator($request);
        $workspaceId = $this->workspaceId($request);

        return Inertia::render('client/Restaurant/MessagingSettings', [
            'outlets' => RestaurantOutlet::query()
                ->where('workspace_id', $workspaceId)
                ->orderBy('name')
                ->get()
                ->map(fn (RestaurantOutlet $outlet): array => $this->outletPayload($outlet))
                ->values(),
        ]);
    }

    public function update(Request $request, RestaurantOutlet $outlet): RedirectResponse
    {
        $this->ensureWorkspaceAdministrator($request);
        $workspaceId = $this->workspaceId($request);

        // Route binding is already constrained by BelongsToWorkspace. Keep the
        // explicit boundary too: an outlet from another workspace is a 404, not
        // a writable resource whose existence a crafted URL can reveal.
        abort_unless((int) $outlet->workspace_id === $workspaceId, 404);

        $data = $request->validate([
            'digital_bill_enabled' => ['present', 'boolean'],
            'feedback_request_enabled' => ['present', 'boolean'],
        ]);

        app(RestaurantOutletService::class)->updateMessagingSettings(
            outlet: $outlet,
            digitalBillEnabled: (bool) $data['digital_bill_enabled'],
            feedbackRequestEnabled: (bool) $data['feedback_request_enabled'],
            actor: $request->user(),
        );

        return back()->with('success', 'Messaging settings updated. No messages were sent.');
    }

    private function ensureWorkspaceAdministrator(Request $request): void
    {
        abort_unless($request->user()?->client_role === User::CLIENT_ROLE_ADMINISTRATOR, 403);
    }

    private function workspaceId(Request $request): int
    {
        return (int) (WorkspaceContext::id() ?? $request->user()->workspace_id);
    }

    /** @return array{uuid: string, name: string, digital_bill_enabled: bool, feedback_request_enabled: bool} */
    private function outletPayload(RestaurantOutlet $outlet): array
    {
        return [
            'uuid' => $outlet->uuid,
            'name' => $outlet->name,
            'digital_bill_enabled' => $outlet->digital_bill_enabled,
            'feedback_request_enabled' => $outlet->feedback_request_enabled,
        ];
    }
}
