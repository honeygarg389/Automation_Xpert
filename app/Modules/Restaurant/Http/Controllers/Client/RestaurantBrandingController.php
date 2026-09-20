<?php

namespace App\Modules\Restaurant\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Restaurant\Models\RestaurantBrandProfile;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Restaurant\Services\RestaurantBrandProfileService;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RestaurantBrandingController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $this->workspace($request);
        $profile = RestaurantBrandProfile::query()->where('workspace_id', $workspace->id)->first();

        return Inertia::render('client/Restaurant/Branding', [
            'profile' => $this->profile($profile),
            'outlets' => RestaurantOutlet::query()->where('workspace_id', $workspace->id)->orderBy('name')->get(['id', 'uuid', 'name', 'public_phone', 'public_website']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $data = $this->validated($request);
        app(RestaurantBrandProfileService::class)->updateProfile($workspace, $data, $request->file('logo'), $request->file('cover'), $request->user());

        return back()->with('success', 'Restaurant branding updated. This does not send any message.');
    }

    public function updateOutlet(Request $request, RestaurantOutlet $outlet): RedirectResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($outlet->workspace_id === $workspace->id, 404);
        $data = $request->validate(['public_phone' => ['nullable', 'string', 'max:32'], 'public_website' => ['nullable', 'url:http,https', 'max:255']]);
        app(RestaurantBrandProfileService::class)->updateOutletPublicContact($outlet, $data['public_phone'] ?? null, $data['public_website'] ?? null, $request->user());

        return back()->with('success', 'Outlet public contact details updated. This does not send any message.');
    }

    private function workspace(Request $request): Workspace
    {
        abort_unless($request->user()?->client_role === User::CLIENT_ROLE_ADMINISTRATOR, 403);
        $id = (int) (WorkspaceContext::id() ?? $request->user()->workspace_id);

        return Workspace::findOrFail($id);
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
