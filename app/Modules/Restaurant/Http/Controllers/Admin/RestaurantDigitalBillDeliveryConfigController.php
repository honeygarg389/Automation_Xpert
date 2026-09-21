<?php

namespace App\Modules\Restaurant\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Restaurant\Services\RestaurantDigitalBillDeliveryConfigService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class RestaurantDigitalBillDeliveryConfigController extends Controller
{
    public function update(Request $request, RestaurantOutlet $outlet, RestaurantDigitalBillDeliveryConfigService $service): RedirectResponse
    {
        $data = $request->validate([
            'whatsapp_phone_number_id' => ['required', 'integer'],
            'whatsapp_template_id' => ['required', 'integer'],
        ]);

        $service->save($outlet, (int) $data['whatsapp_phone_number_id'], (int) $data['whatsapp_template_id'], $request->user('admin'));

        return back()->with('success', 'Digital Bill delivery configuration saved. Configuration is revalidated before any future delivery.');
    }
}
