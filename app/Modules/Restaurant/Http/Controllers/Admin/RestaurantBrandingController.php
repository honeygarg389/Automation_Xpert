<?php

namespace App\Modules\Restaurant\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Modules\Restaurant\Models\RestaurantBrandProfile;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Restaurant\Services\RestaurantBrandProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RestaurantBrandingController extends Controller
{
    public function index(Request $request): Response
    {
        $workspaceId = $request->integer('workspace_id');
        $workspace = $workspaceId ? Workspace::findOrFail($workspaceId) : null;
        $profile = $workspace ? RestaurantBrandProfile::query()->where('workspace_id', $workspace->id)->first() : null;

        return Inertia::render('Admin/Restaurant/Branding', [
            'workspace' => $workspace ? ['id' => $workspace->id, 'name' => $workspace->name] : null,
            'workspaces' => Workspace::query()->orderBy('name')->get(['id', 'name']),
            'profile' => $this->profile($profile),
            'outlets' => $workspace ? RestaurantOutlet::query()->where('workspace_id', $workspace->id)->orderBy('name')->get(['id', 'uuid', 'name', 'address', 'public_phone', 'public_email', 'public_website', 'gstin', 'fssai_number']) : [],
        ]);
    }

    public function update(Request $request, Workspace $workspace): RedirectResponse
    {
        $data = $this->validated($request);
        app(RestaurantBrandProfileService::class)->updateProfile($workspace, $data, $request->file('logo'), $request->file('cover'), $request->user('admin'));

        return back()->with('success', 'Restaurant branding updated. This does not send any message.');
    }

    public function updateOutlet(Request $request, Workspace $workspace, RestaurantOutlet $outlet): RedirectResponse
    {
        abort_unless($outlet->workspace_id === $workspace->id, 404);
        // Preserve the legacy public-contact endpoint's audit semantics for
        // integrations still sending exactly its historic two-field payload.
        if (! $request->hasAny(['name', 'address', 'public_email', 'gstin', 'fssai_number'])) {
            $data = $request->validate(['public_phone' => ['nullable', 'string', 'max:32'], 'public_website' => ['nullable', 'url:http,https', 'max:255']]);
            app(RestaurantBrandProfileService::class)->updateOutletPublicContact($outlet, $data['public_phone'] ?? null, $data['public_website'] ?? null, $request->user('admin'));

            return back()->with('success', 'Outlet public contact details updated. This does not send any message.');
        }
        $data = array_replace([
            'name' => $outlet->name, 'address' => $outlet->address, 'public_phone' => $outlet->public_phone,
            'public_email' => $outlet->public_email, 'public_website' => $outlet->public_website,
            'gstin' => $outlet->gstin, 'fssai_number' => $outlet->fssai_number,
        ], $this->outletValidated($request));
        app(RestaurantBrandProfileService::class)->updateOutletProfile($outlet, $data, $request->user('admin'));

        return back()->with('success', 'Outlet public contact details updated. This does not send any message.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'brand_name' => ['nullable', 'string', 'max:128'],
            'legal_business_name' => ['nullable', 'string', 'max:180'],
            'registered_business_address' => ['nullable', 'string', 'max:2000'],
            'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'thank_you_note' => ['nullable', 'string', 'max:1000'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'social_links' => ['nullable', 'array:instagram,facebook,google,x,youtube'],
            'social_links.*' => ['nullable', 'url:http,https', 'max:255'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'cover' => ['nullable', 'image', 'max:4096'],
        ]);
    }

    /** @return array{name:string,address:?string,public_phone:?string,public_email:?string,public_website:?string,gstin:?string,fssai_number:?string} */
    private function outletValidated(Request $request): array
    {
        /** @var array{name:string,address:?string,public_phone:?string,public_email:?string,public_website:?string,gstin:?string,fssai_number:?string} $data */
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:128'], 'address' => ['sometimes', 'nullable', 'string', 'max:512'], 'public_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'public_email' => ['nullable', 'email:rfc', 'max:255'], 'public_website' => ['nullable', 'url:http,https', 'max:255'],
            'gstin' => ['nullable', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]Z[0-9A-Z]$/'], 'fssai_number' => ['nullable', 'regex:/^[0-9]{14}$/'],
        ]);

        return $data;
    }

    /** @return array<string, mixed> */
    private function profile(?RestaurantBrandProfile $profile): array
    {
        return [
            'brand_name' => $profile?->brand_name,
            'legal_business_name' => $profile?->legal_business_name,
            'registered_business_address' => $profile?->registered_business_address,
            'primary_color' => $profile?->primary_color,
            'thank_you_note' => $profile?->thank_you_note,
            'website' => $profile?->website,
            'social_links' => $profile === null ? [] : ($profile->social_links ?? []),
            'logo_url' => $profile?->logoUrl(),
            'cover_url' => $profile?->coverUrl(),
        ];
    }
}
