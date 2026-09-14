<?php

namespace App\Modules\Flows\Http\Middleware;

use App\Modules\Entitlements\Support\Entitlements;
use App\Support\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The protection half of the WhatsApp Flows plan gate.
 *
 * Hiding the sidebar entry is presentation only. Direct requests make the
 * same entitlement decision here after client-app resolves the workspace.
 */
class EnsureFlowsEnabled
{
    public const KEY = 'whatsapp_flows_enabled';

    public function __construct(private readonly Entitlements $entitlements) {}

    public function handle(Request $request, Closure $next): Response
    {
        $workspaceId = WorkspaceContext::id() ?? $request->user()?->workspace_id;

        if ($workspaceId === null) {
            abort(403);
        }

        if (! $this->entitlements->forWorkspace((int) $workspaceId)->allows(self::KEY)) {
            abort(403);
        }

        return $next($request);
    }
}
