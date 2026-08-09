<?php

namespace App\Http\Middleware;

use App\Models\Plan;
use App\Models\Workspace;
use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Entitlements\Support\Entitlements;
use App\Modules\Entitlements\Support\QuotaGuard;
use App\Support\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to enforce plan-based feature limits.
 *
 * Usage in routes:
 *   Route::post('/...')->middleware('limit:campaigns_per_month,campaigns');
 *
 * Parameters:
 *   $limitKey  – key in Plan.limits JSON (e.g. 'campaigns_per_month')
 *   $countKey  – usage_meters metric (e.g. 'campaigns')
 *
 * ─── ⚠️ THIS MIDDLEWARE HAS NEVER BLOCKED ANYTHING ──────────────────────────
 *
 * Three independent defects, each sufficient on its own:
 *
 *   1. `UsageMeter::track()` reset the counter to zero on every increment, so
 *      `$usage` was always the size of the last increment. Fixed alongside this;
 *      see BUG-022. That fix alone makes this middleware live for the
 *      admin-assigned cohort — deliberately, and it is the smaller cohort.
 *
 *   2. The plan came from `activePlan()`, which reads `client_subscriptions`
 *      only. Self-serve customers bill through `subscriptions`, so their plan
 *      resolved to null and every limit read as unlimited. BUG-023 — addressed
 *      here in REPORT-ONLY mode, see below.
 *
 *   3. Seven of the fourteen seeded limit keys are cardinality limits ("how many
 *      chatbots may exist") checked against a per-period counter that nothing
 *      increments, so they read 0 forever. BUG-024 — NOT fixed here; it needs
 *      the counter/gauge distinction, which belongs to the entitlement resolver.
 *
 * ─── Report-only ────────────────────────────────────────────────────────────
 *
 * Defect 2 is not shipped hot. Correcting the plan source turns on enforcement
 * for every customer who has been silently unmetered since launch, and the way
 * they find out is a 402 mid-campaign. So while
 * `entitlements.enforce_effective_plan_source` is false, this middleware
 * enforces exactly as it did before and merely LOGS the requests the corrected
 * source would have refused. Size the cohort from those logs, then flip.
 */
class EnforceLimit
{
    public function handle(Request $request, Closure $next, string $limitKey, string $countKey = ''): Response
    {
        $user = $request->user();
        // Was `current_workspace_id ?? workspace_id`, which always yielded the
        // HOME workspace — so plan limits were enforced against the wrong
        // workspace whenever a user had switched. See plan §G-3.
        $workspaceId = WorkspaceContext::id();

        if (! $workspaceId || ! $user) {
            return $next($request);
        }

        $workspace = Workspace::with('client')->find($workspaceId);
        $client = $workspace?->client;
        $meterKey = $countKey ?: $limitKey;

        $useEffective = (bool) config('entitlements.enforce_effective_plan_source', false);

        // The source that ENFORCES. Unchanged until the flag is flipped.
        $enforcingPlan = $useEffective ? $client?->effectivePlan() : $client?->activePlan();

        // ⚠️ THE BRAKE. The legacy expression stays right here, beside the new
        // one, so an operator comparing the two during an incident does not have
        // to reconstruct the old behaviour from git history.
        //
        // The resolver reads the SAME plan source, including the
        // enforce_effective_plan_source flag, so these two lines agree by
        // construction rather than by luck. That is the whole point: BUG-023 was
        // two places deciding which subscription is in effect.
        $limit = Entitlements::isEnabled()
            ? app(Entitlements::class)->forClient($client)->limit($limitKey)
            : $this->limitFrom($enforcingPlan, $limitKey);

        if (! $useEffective) {
            $this->reportWhatTheCorrectSourceWouldDo(
                $request, $workspaceId, $client?->effectivePlan(), $limit, $limitKey, $meterKey
            );
        }

        // null = unlimited
        if ($limit === null) {
            return $next($request);
        }

        // The comparison lives in QuotaGuard so the campaign job asks the same
        // question the same way. Two places computing "is this workspace at its
        // limit" is how the WhatsApp metric split survived: the inbox path
        // checked a meter the campaign path was not feeding.
        $usage = app(QuotaGuard::class)->usage($workspaceId, $meterKey);

        if ($usage >= $limit) {
            return $this->refuse($request, $limitKey, $limit, $usage);
        }

        return $next($request);
    }

    /** A plan's limit for `$key`. null means unlimited — and so does no plan at all. */
    private function limitFrom(?Plan $plan, string $key): ?int
    {
        $value = ($plan?->limits ?? [])[$key] ?? null;

        return $value === null ? null : (int) $value;
    }

    /**
     * REPORT-ONLY. Logs the requests the corrected plan source would have
     * refused but the current one allows.
     *
     * Only the divergence is logged, not every request: the point is to size the
     * cohort that a flip would start refusing. If the corrected source agrees
     * with the current one, there is nothing to decide.
     *
     * Never throws, and never blocks. A defect in an observability path must not
     * become an outage on the path it is observing.
     */
    private function reportWhatTheCorrectSourceWouldDo(
        Request $request,
        int $workspaceId,
        ?Plan $effectivePlan,
        ?int $currentLimit,
        string $limitKey,
        string $meterKey
    ): void {
        try {
            $shadowLimit = $this->limitFrom($effectivePlan, $limitKey);

            // Only a shadow limit that BITES where the current one does not is
            // news. Equal limits, or a shadow that is also unlimited, are not.
            if ($shadowLimit === null) {
                return;
            }

            $usage = UsageMeter::current($workspaceId, $meterKey);

            if ($usage < $shadowLimit) {
                return;
            }

            if ($currentLimit !== null && $usage >= $currentLimit) {
                return; // Already refused on the current source. Not a divergence.
            }

            // Its OWN channel, at a hard-coded level. The app default is
            // LOG_LEVEL=error, under which a warning() here writes nothing at
            // all — and an empty report-only log reads as "nobody affected".
            Log::channel('entitlements')->warning(
                'entitlements.report_only: request would be refused once ENFORCE_EFFECTIVE_PLAN_SOURCE is on',
                [
                    'workspace_id' => $workspaceId,
                    'limit_key' => $limitKey,
                    'metric' => $meterKey,
                    'usage' => $usage,
                    'shadow_limit' => $shadowLimit,
                    'current_limit' => $currentLimit,
                    'plan_id' => $effectivePlan?->id,
                    'route' => $request->route()?->getName(),
                ]
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function refuse(Request $request, string $limitKey, int $limit, int $usage): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'error' => 'Plan limit reached.',
                'upgrade_required' => true,
                'limit' => $limit,
                'current' => $usage,
                'key' => $limitKey,
            ], 402);
        }

        return redirect('/billing')->with('upgrade_required', true)
            ->with('upgrade_reason', "You've reached your {$limitKey} limit ({$usage}/{$limit}).");
    }
}
