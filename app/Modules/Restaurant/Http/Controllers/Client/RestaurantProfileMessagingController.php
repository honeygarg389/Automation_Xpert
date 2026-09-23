<?php

namespace App\Modules\Restaurant\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Restaurant\Models\RestaurantBrandProfile;
use App\Modules\Restaurant\Models\RestaurantFeedbackDeliveryConfig;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Restaurant\Services\RestaurantBrandProfileService;
use App\Modules\Restaurant\Services\RestaurantDigitalBillDeliveryConfigService;
use App\Modules\Restaurant\Services\RestaurantFeedbackDeliveryConfigService;
use App\Modules\Restaurant\Services\RestaurantOutletService;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** One owner-only configuration surface; deliberately contains no delivery behavior. */
final class RestaurantProfileMessagingController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $this->workspace($request);
        $digital = app(RestaurantDigitalBillDeliveryConfigService::class);
        $feedback = app(RestaurantFeedbackDeliveryConfigService::class);
        $outlets = RestaurantOutlet::query()->where('workspace_id', $workspace->id)->with(['digitalBillDeliveryConfig', 'feedbackDeliveryConfig'])->orderBy('name')->get();

        return Inertia::render('client/Restaurant/ProfileMessaging', [
            'profile' => $this->profile(RestaurantBrandProfile::query()->where('workspace_id', $workspace->id)->first()),
            'outlets' => $outlets->map(fn (RestaurantOutlet $outlet): array => $this->outlet($outlet))->values(),
            'digitalBillDeliveryOptions' => $digital->optionsForWorkspace($workspace->id),
            'feedbackDeliveryOptions' => $feedback->optionsForWorkspace($workspace->id),
            'feedbackTimingPreferences' => RestaurantFeedbackDeliveryConfig::TIMING_PREFERENCES,
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $workspace = $this->workspace($request);
        app(RestaurantBrandProfileService::class)->updateProfile($workspace, $this->profileInput($request), $request->file('logo'), $request->file('cover'), $request->user());

        return back()->with('success', 'Restaurant profile updated. This does not send any message.');
    }

    public function updateOutlet(Request $request, string $outlet): RedirectResponse
    {
        $outlet = $this->outletForRequest($request, $outlet);
        app(RestaurantBrandProfileService::class)->updateOutletProfile($outlet, $this->outletInput($request), $request->user());

        return back()->with('success', 'Outlet billing details updated. This does not send any message.');
    }

    public function updateMessaging(Request $request, string $outlet): RedirectResponse
    {
        $outlet = $this->outletForRequest($request, $outlet);
        $data = $request->validate(['digital_bill_enabled' => ['present', 'boolean'], 'feedback_request_enabled' => ['present', 'boolean']]);
        app(RestaurantOutletService::class)->updateMessagingSettings($outlet, (bool) $data['digital_bill_enabled'], (bool) $data['feedback_request_enabled'], $request->user());

        return back()->with('success', 'Messaging settings updated. No messages were sent.');
    }

    public function updateDigitalBillConfig(Request $request, string $outlet, RestaurantDigitalBillDeliveryConfigService $service): RedirectResponse
    {
        $outlet = $this->outletForRequest($request, $outlet);
        $data = $request->validate(['whatsapp_phone_number_id' => ['required', 'integer'], 'whatsapp_template_id' => ['required', 'integer']]);
        $service->save($outlet, (int) $data['whatsapp_phone_number_id'], (int) $data['whatsapp_template_id'], $request->user());

        return back()->with('success', 'Digital Bill delivery configuration saved. Configuration is revalidated before any future delivery.');
    }

    public function updateFeedbackConfig(Request $request, string $outlet, RestaurantFeedbackDeliveryConfigService $service): RedirectResponse
    {
        $outlet = $this->outletForRequest($request, $outlet);
        $data = $request->validate([
            'whatsapp_phone_number_id' => ['required', 'integer'],
            'whatsapp_template_id' => ['required', 'integer'],
            'timing_preference' => ['required', 'in:'.implode(',', RestaurantFeedbackDeliveryConfig::TIMING_PREFERENCES)],
            'next_day_at' => ['nullable', 'date_format:H:i', 'required_if:timing_preference,next_day'],
            'google_review_url' => ['nullable', 'url:http,https', 'max:255'],
        ]);
        if ($data['timing_preference'] !== RestaurantFeedbackDeliveryConfig::TIMING_NEXT_DAY) {
            $data['next_day_at'] = null;
        }
        $service->save($outlet, $data, $request->user());

        return back()->with('success', 'Feedback configuration saved. Configuration is revalidated before any future delivery.');
    }

    private function workspace(Request $request): Workspace
    {
        abort_unless($request->user()?->client_role === User::CLIENT_ROLE_ADMINISTRATOR, 403);

        return Workspace::findOrFail((int) (WorkspaceContext::id() ?? $request->user()->workspace_id));
    }

    private function outletForRequest(Request $request, string $uuid): RestaurantOutlet
    {
        $workspace = $this->workspace($request);

        return RestaurantOutlet::query()->where('workspace_id', $workspace->id)->where('uuid', $uuid)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function profileInput(Request $request): array
    {
        return $request->validate([
            'brand_name' => ['nullable', 'string', 'max:128'], 'legal_business_name' => ['nullable', 'string', 'max:180'],
            'registered_business_address' => ['nullable', 'string', 'max:2000'], 'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'thank_you_note' => ['nullable', 'string', 'max:1000'], 'website' => ['nullable', 'url:http,https', 'max:255'],
            'social_links' => ['nullable', 'array:instagram,facebook,google,youtube,x'], 'social_links.*' => ['nullable', 'url:http,https', 'max:255'],
            'logo' => ['nullable', 'image', 'max:2048'], 'cover' => ['nullable', 'image', 'max:4096'],
        ]);
    }

    /** @return array{name:string,address:?string,public_phone:?string,public_email:?string,public_website:?string,gstin:?string,fssai_number:?string} */
    private function outletInput(Request $request): array
    {
        /** @var array{name:string,address:?string,public_phone:?string,public_email:?string,public_website:?string,gstin:?string,fssai_number:?string} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'], 'address' => ['nullable', 'string', 'max:512'],
            'public_phone' => ['nullable', 'string', 'max:32'], 'public_email' => ['nullable', 'email:rfc', 'max:255'],
            'public_website' => ['nullable', 'url:http,https', 'max:255'],
            'gstin' => ['nullable', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]Z[0-9A-Z]$/'],
            'fssai_number' => ['nullable', 'regex:/^[0-9]{14}$/'],
        ]);

        return $data;
    }

    /** @return array<string, mixed> */
    private function profile(?RestaurantBrandProfile $profile): array
    {
        return ['brand_name' => $profile?->brand_name, 'legal_business_name' => $profile?->legal_business_name, 'registered_business_address' => $profile?->registered_business_address, 'primary_color' => $profile?->primary_color, 'thank_you_note' => $profile?->thank_you_note, 'website' => $profile?->website, 'social_links' => $profile === null ? [] : ($profile->social_links ?? []), 'logo_url' => $profile?->logoUrl(), 'cover_url' => $profile?->coverUrl()];
    }

    /** @return array<string, mixed> */
    private function outlet(RestaurantOutlet $outlet): array
    {
        $feedback = $outlet->feedbackDeliveryConfig;

        return ['uuid' => $outlet->uuid, 'name' => $outlet->name, 'address' => $outlet->address, 'status' => $outlet->status, 'public_phone' => $outlet->public_phone, 'public_email' => $outlet->public_email, 'public_website' => $outlet->public_website, 'gstin' => $outlet->gstin, 'fssai_number' => $outlet->fssai_number, 'digital_bill_enabled' => $outlet->digital_bill_enabled, 'feedback_request_enabled' => $outlet->feedback_request_enabled, 'digital_bill_delivery_config' => $outlet->digitalBillDeliveryConfig?->only(['whatsapp_phone_number_id', 'whatsapp_template_id']), 'feedback_delivery_config' => $feedback === null ? null : ['whatsapp_phone_number_id' => $feedback->whatsapp_phone_number_id, 'whatsapp_template_id' => $feedback->whatsapp_template_id, 'timing_preference' => $feedback->timing_preference, 'next_day_at' => $feedback->next_day_at?->format('H:i'), 'google_review_url' => $feedback->google_review_url]];
    }
}
