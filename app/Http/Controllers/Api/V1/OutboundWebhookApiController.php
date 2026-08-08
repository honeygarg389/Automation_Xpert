<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\WebhookEndpoint;
use App\Rules\PublicHttpUrl;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OutboundWebhookApiController extends WorkspaceScopedController
{
    private const ALLOWED_EVENTS = [
        'contact.created',
        'contact.updated',
        'message.received',
        'message.sent',
        'campaign.completed',
        'automation.run.completed',
    ];

    /**
     * GET /api/v1/webhooks
     */
    public function index(Request $request): JsonResponse
    {
        $endpoints = WebhookEndpoint::where('user_id', $request->user()->id)
            ->latest('id')
            ->get()
            ->map(fn ($ep) => $this->format($ep));

        return response()->json(['data' => $endpoints]);
    }

    /**
     * POST /api/v1/webhooks
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:500', new PublicHttpUrl],
            'events' => ['nullable', 'array'],
            'events.*' => ['string', 'in:'.implode(',', self::ALLOWED_EVENTS)],
            'description' => ['nullable', 'string', 'max:200'],
        ]);

        $secret = WebhookEndpoint::generateSecret();
        // Phase 0 slice 9: workspace_id is NOT NULL now. A user with no workspace
        // would otherwise hit an integrity-constraint 500 on insert; refuse
        // cleanly instead. This is not reachable through the normal signup flow,
        // which always creates a workspace — it is the defensive half of making
        // the column required.
        $workspaceId = WorkspaceContext::id() ?? $request->user()->workspace_id;
        abort_if($workspaceId === null, 422, 'This account is not attached to a workspace.');

        $endpoint = WebhookEndpoint::create([
            'user_id' => $request->user()->id,
            // Phase 0 slice 9: webhook_endpoints is workspace-owned now.
            'workspace_id' => $workspaceId,
            'url' => $validated['url'],
            'events' => $validated['events'] ?? [],
            'description' => $validated['description'] ?? null,
            'secret' => $secret,
            'enabled' => true,
        ]);

        return response()->json(array_merge($this->format($endpoint), ['secret' => $secret]), 201);
    }

    /**
     * DELETE /api/v1/webhooks/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $deleted = WebhookEndpoint::where('user_id', $request->user()->id)->where('id', $id)->delete();

        if (! $deleted) {
            return response()->json(['error' => 'Webhook endpoint not found.'], 404);
        }

        return response()->json(['ok' => true]);
    }

    private function format(WebhookEndpoint $ep): array
    {
        return [
            'id' => $ep->id,
            'url' => $ep->url,
            'events' => $ep->events ?? [],
            'enabled' => $ep->enabled,
            'description' => $ep->description,
            'created_at' => $ep->created_at->toIso8601String(),
        ];
    }
}
