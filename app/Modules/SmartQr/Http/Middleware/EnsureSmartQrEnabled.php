<?php

namespace App\Modules\SmartQr\Http\Middleware;

use App\Modules\Entitlements\Support\Entitlements;
use App\Support\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * R-5's third key: `smart_qr_enabled`, a BOOLEAN checked at DISPLAY.
 *
 * ⚠️ HIDDEN ENTIRELY, not present-and-empty.
 *
 * A customer without the feature gets 403 here and no nav entry (the flag is
 * shared to the front end and `useClientNav` omits the group). An empty Smart QR
 * section shown to a customer who cannot have Smart QR is an advert placed
 * inside the product — and every other module in this client nav is either
 * present or absent. There is no "present but disabled" precedent to copy.
 *
 * ⚠️ The flag is DERIVED — see PlanPackageSynthesizer::legacyFlags(). Gating on
 * the flag as it stood before slice 6 would have hidden the module from every
 * customer on every plan, because nothing could set it (R-22).
 */
class EnsureSmartQrEnabled
{
    public const KEY = 'smart_qr_enabled';

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
