<?php

namespace App\Modules\Restaurant\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Restaurant\Models\RestaurantFeedbackDeliveryConfig;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Restaurant\Services\RestaurantFeedbackDeliveryConfigService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class RestaurantFeedbackDeliveryConfigController extends Controller
{
    public function update(Request $request, RestaurantOutlet $outlet, RestaurantFeedbackDeliveryConfigService $service): RedirectResponse
    {
        $data = $request->validate([
            'whatsapp_phone_number_id' => ['required', 'integer'], 'whatsapp_template_id' => ['required', 'integer'],
            'timing_preference' => ['required', 'in:'.implode(',', RestaurantFeedbackDeliveryConfig::TIMING_PREFERENCES)],
            'next_day_at' => ['nullable', 'date_format:H:i', 'required_if:timing_preference,next_day'],
            'google_review_url' => ['nullable', 'url:http,https', 'max:255'],
        ]);
        if ($data['timing_preference'] !== RestaurantFeedbackDeliveryConfig::TIMING_NEXT_DAY) {
            $data['next_day_at'] = null;
        }
        $service->save($outlet, $data, $request->user('admin'));

        return back()->with('success', 'Feedback configuration saved. Configuration is revalidated before any future delivery.');
    }
}
