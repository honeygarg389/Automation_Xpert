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
            'outlets' => $workspace ? RestaurantOutlet::query()->where('workspace_id', $workspace->id)->orderBy('name')->get(['id', 'uuid', 'name', 'public_phone', 'public_website']) : [],
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
        $data = $request->validate(['public_phone' => ['nullable', 'string', 'max:32'], 'public_website' => ['nullable', 'url:http,https', 'max:255']]);
        app(RestaurantBrandProfileService::class)->updateOutletPublicContact($outlet, $data['public_phone'] ?? null, $data['public_website'] ?? null, $request->user('admin'));

        return back()->with('success', 'Outlet public contact details updated. This does not send any message.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'brand_name' => ['nullable', 'string', 'max:128'],
            'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'thank_you_note' => ['nullable', 'string', 'max:1000'],
            'social_links' => ['nullable', 'array:instagram,facebook,x,youtube'],
            'social_links.*' => ['nullable', 'url:http,https', 'max:255'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'cover' => ['nullable', 'image', 'max:4096'],
        ]);
    }

    /** @return array<string, mixed> */
    private function profile(?RestaurantBrandProfile $profile): array
    {
        return [
            'brand_name' => $profile?->brand_name,
            'primary_color' => $profile?->primary_color,
            'thank_you_note' => $profile?->thank_you_note,
            'social_links' => $profile === null ? [] : ($profile->social_links ?? []),
            'logo_url' => $profile?->logoUrl(),
            'cover_url' => $profile?->coverUrl(),
        ];
    }
}
