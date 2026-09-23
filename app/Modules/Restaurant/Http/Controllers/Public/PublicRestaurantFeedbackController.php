<?php

namespace App\Modules\Restaurant\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Restaurant\Models\RestaurantBrandProfile;
use App\Modules\Restaurant\Models\RestaurantFeedbackAlert;
use App\Modules\Restaurant\Models\RestaurantFeedbackDeliveryConfig;
use App\Modules\Restaurant\Models\RestaurantFeedbackRequest;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PublicRestaurantFeedbackController extends Controller
{
    public function show(string $token): Response
    {
        [$feedback, $brand, $reviewUrl] = $this->publicData($token);

        return response()->view('restaurant.public-feedback', compact('feedback', 'brand', 'reviewUrl'))
            ->header('Cache-Control', 'no-store, private')
            ->header('Pragma', 'no-cache');
    }

    public function submit(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:2000']]);
        [$feedback] = $this->publicData($token);
        WorkspaceContext::for($feedback->workspace_id, function () use ($feedback, $data): void {
            $updated = RestaurantFeedbackRequest::query()->whereKey($feedback->id)->whereNull('submitted_at')->update([
                'rating' => $data['rating'], 'customer_comment' => $data['comment'] ?? null, 'submitted_at' => now(), 'updated_at' => now(),
            ]);
            if ($updated !== 1 || $data['rating'] > 3) {
                return;
            }
            RestaurantFeedbackAlert::query()->firstOrCreate([
                'restaurant_feedback_request_id' => $feedback->id,
            ], ['workspace_id' => $feedback->workspace_id, 'outlet_id' => $feedback->outlet_id, 'status' => 'pending']);
            RestaurantFeedbackRequest::query()->whereKey($feedback->id)->whereNull('manager_alerted_at')->update(['manager_alerted_at' => now(), 'updated_at' => now()]);
        });

        return redirect()->route('public.restaurant.feedback.show', ['token' => $token]);
    }

    /** @return array{RestaurantFeedbackRequest, array{name:string,logo_url:?string,primary_color:string}, ?string} */
    private function publicData(string $token): array
    {
        /** @var RestaurantFeedbackRequest|null $candidate */
        $candidate = RestaurantFeedbackRequest::withoutWorkspaceScope('reason: unauthenticated public feedback route discovers exactly one workspace from an opaque token before scoped reads begin.')
            ->where('public_token', $token)->where('status', RestaurantFeedbackRequest::STATUS_SENT)->whereNull('revoked_at')->first();
        if ($candidate === null) {
            abort(404);
        }

        return WorkspaceContext::for($candidate->workspace_id, function () use ($candidate): array {
            /** @var RestaurantFeedbackRequest|null $feedback */
            $feedback = RestaurantFeedbackRequest::query()->find($candidate->id);
            if ($feedback === null || $feedback->revoked_at !== null || $feedback->status !== RestaurantFeedbackRequest::STATUS_SENT) {
                abort(404);
            }
            $profile = RestaurantBrandProfile::query()->where('workspace_id', $feedback->workspace_id)->first();
            $config = $feedback->outlet_id === null ? null : RestaurantFeedbackDeliveryConfig::query()->where('outlet_id', $feedback->outlet_id)->first();
            $brand = ['name' => $profile?->brand_name ?: 'Restaurant', 'logo_url' => $profile?->logoUrl(), 'primary_color' => $profile?->primary_color ?: '#2563eb'];

            return [$feedback, $brand, $config?->google_review_url];
        });
    }
}
