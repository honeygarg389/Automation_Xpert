<?php

namespace App\Modules\SmartQr\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\SmartQr\Services\SmartQrAccess;
use App\Modules\SmartQr\Services\SmartQrMetrics;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §11 A (Overview) and C (Activity). The tenant-facing side.
 *
 * ⚠️ THE FIRST CUSTOMER-FACING SURFACE IN THIS MODULE, and the place a scoping
 * mistake shows one customer another tenant's QR codes.
 *
 * Every read goes through `SmartQrAccess`, which is the only sanctioned way to
 * reach `SmartQrCode` — that model has no global scope (R-4), so a raw query
 * here would return every tenant's rows and the failure would be SILENT: a list
 * that quietly includes codes belonging to somebody else.
 *
 * `SmartQrAccessGuardTest` fails the build on any `SmartQrCode::` outside the
 * access service and the admin namespace. This namespace is deliberately not on
 * that allowlist and must not be added to it.
 *
 * ⚠️ §11 B is deliberately absent from this controller — see
 * SmartQrCodeController. §11 D (Settings) is not built at all: R-23.
 */
class SmartQrDashboardController extends Controller
{
    public function __construct(
        private readonly SmartQrMetrics $metrics,
        private readonly SmartQrAccess $access,
    ) {}

    public function overview(Request $request): Response
    {
        $workspaceId = $this->workspaceId($request);

        return Inertia::render('client/SmartQr/Overview', [
            'kpis' => $this->metrics->overview($workspaceId),
            'recentCodes' => $this->access->codesFor($workspaceId)
                ->with(['currentAssignment:id,smart_qr_code_id,name,qr_type,status'])
                ->latest('id')
                ->limit(5)
                ->get(['id', 'serial_number', 'status']),
        ]);
    }

    public function activity(Request $request): Response
    {
        return Inertia::render('client/SmartQr/Activity', [
            'scans' => $this->metrics->activity($this->workspaceId($request)),
        ]);
    }

    /**
     * ⚠️ Context first, the user's home workspace second — matching
     * Client\DashboardController. WorkspaceContext is what the switcher sets,
     * so reading `$user->workspace_id` alone would show a customer with two
     * workspaces the wrong one's codes after switching.
     */
    private function workspaceId(Request $request): int
    {
        return (int) (WorkspaceContext::id() ?? $request->user()->workspace_id);
    }
}
