<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientSubscription;
use App\Models\Subscription;
use App\Modules\Entitlements\Support\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionApiController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $effective = $user->effectiveSubscription();

        if (! $effective) {
            return response()->json(['data' => null]);
        }

        $plan = $effective->plan;

        return response()->json([
            'data' => [
                'plan' => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'slug' => $plan->slug,
                ],
                'billing_cycle' => $effective instanceof Subscription ? $effective->billing_cycle : null,
                'status' => $effective->status ?? ($effective->isActive() ? 'active' : 'inactive'),
                'renews_at' => $effective instanceof Subscription ? $effective->renews_at?->toIso8601String() : null,
                'ends_at' => $effective->ends_at?->toIso8601String(),
                'gateway' => $effective instanceof Subscription ? $effective->gateway : null,
                'managed_by_admin' => $effective instanceof ClientSubscription,
            ],
        ]);
    }

    public function usage(Request $request): JsonResponse
    {
        $user = $request->user();
        $entitlements = app(Entitlements::class);
        $plan = $user->effectiveSubscription()?->plan;

        return response()->json([
            'data' => [
                'plan_name' => $plan?->name,
                // Entitlement question: what may the CALLER do. Same plan source
                // as the resolver, so the values are unchanged — and storage_gb
                // stays null either way, because no plan carries that key
                // (BUG-025). Preserved rather than quietly repointed at
                // `storage`, which would change every customer's reported quota.
                'limits' => $plan ? [
                    'users' => $entitlements->limitForClient($user->client, 'users'),
                    'storage_gb' => $entitlements->limitForClient($user->client, 'storage_gb'),
                ] : null,
            ],
        ]);
    }
}
