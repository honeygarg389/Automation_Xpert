<?php

namespace App\Modules\Restaurant\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Restaurant\Services\RestaurantDigitalBillDeliveryConfigService;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class RestaurantDigitalBillDeliveryConfigController extends Controller
{
    public function update(Request $request, string $outlet, RestaurantDigitalBillDeliveryConfigService $service): RedirectResponse
    {
        abort_unless($request->user()?->client_role === User::CLIENT_ROLE_ADMINISTRATOR, 403);
        $workspaceId = (int) (WorkspaceContext::id() ?? $request->user()->workspace_id);

        // Do not use implicit binding here: the web group binds before the
        // client-app role guard, which would turn a valid same-workspace staff
        // request into a misleading 404. Authorize first, then resolve inside
        // the trusted active workspace so foreign outlet UUIDs remain a 404.
        $outlet = RestaurantOutlet::query()
            ->where('workspace_id', $workspaceId)
            ->where('uuid', $outlet)
            ->firstOrFail();

        $data = $request->validate([
            'whatsapp_phone_number_id' => ['required', 'integer'],
            'whatsapp_template_id' => ['required', 'integer'],
        ]);

        $service->save($outlet, (int) $data['whatsapp_phone_number_id'], (int) $data['whatsapp_template_id'], $request->user());

        return back()->with('success', 'Digital Bill delivery configuration saved. Configuration is revalidated before any future delivery.');
    }
}
