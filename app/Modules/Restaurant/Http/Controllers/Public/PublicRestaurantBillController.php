<?php

namespace App\Modules\Restaurant\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Modules\Restaurant\Models\RestaurantBill;
use App\Modules\Restaurant\Models\RestaurantBrandProfile;
use App\Modules\Restaurant\Services\PublicRestaurantBillPresenter;
use App\Support\WorkspaceContext;
use Illuminate\Http\Response;

class PublicRestaurantBillController extends Controller
{
    public function __invoke(string $token, PublicRestaurantBillPresenter $presenter): Response
    {
        // No guest can have a WorkspaceContext. The opaque 256-bit token is
        // the one narrow routing boundary; every scoped read happens after it
        // supplies the workspace, and unknown/revoked cases fold to one 404.
        $candidate = RestaurantBill::withoutWorkspaceScope('reason: unauthenticated public bill route must discover its workspace from a 256-bit opaque token before normal scoped reads can begin.')
            ->where('public_token', $token)
            ->whereNull('public_access_revoked_at')
            ->first();

        abort_unless($candidate !== null, 404);

        return WorkspaceContext::for((int) $candidate->workspace_id, function () use ($candidate, $presenter): Response {
            $bill = RestaurantBill::query()->with(['outlet', 'contact'])->find($candidate->id);
            abort_unless($bill !== null, 404);

            $workspace = Workspace::find($bill->workspace_id);
            abort_unless($workspace !== null, 404);
            $brand = RestaurantBrandProfile::query()->where('workspace_id', $bill->workspace_id)->first();

            return response()
                ->view('restaurant.public-bill', ['bill' => $presenter->present($bill, $bill->outlet, $brand, $workspace, $bill->contact)])
                ->header('Cache-Control', 'private, no-store')
                ->header('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
        });
    }
}
