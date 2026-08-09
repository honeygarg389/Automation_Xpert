<?php

namespace App\Modules\Entitlements\Services;

use App\Models\Client;
use App\Models\Workspace;
use App\Modules\Entitlements\Models\AddOn;
use App\Modules\Entitlements\Support\Entitlement;
use App\Modules\Entitlements\Support\GrantBundle;

/**
 * Phase 1, slice 2 — THE CANARY.
 *
 * Answers "what may this workspace do", by folding every holding into one
 * immutable {@see Entitlement}. Pure: no cache, no writes, no events. The
 * materialized read model is a later slice, deliberately — a cache built before
 * its oracle makes a wrong answer impossible to attribute.
 *
 * ─── What it reads TODAY ────────────────────────────────────────────────────
 *
 * Only synthesized packages built from the existing `plans.limits`. No catalog
 * rows are consulted yet, because none exist. The proof obligation is exact: for
 * every seeded plan and every limit key, this must return what `EnforceLimit`
 * reads today. If it does, the mechanism is proven before anything depends on
 * it; if it does not, nothing has been migrated and we find out now.
 *
 * ─── The fold ───────────────────────────────────────────────────────────────
 *
 *   package  DOMINANT — the single highest `rank` wins outright. Never summed.
 *   pack     ADDITIVE — value * quantity, added onto the winning package.
 *   feature  BOOLEAN  — OR across everything held.
 *
 * One `match`, no feature-specific branches, and the fold cannot see where a
 * bundle came from. CLAUDE.md rules 4 and 5.
 *
 * ─── NOT here ───────────────────────────────────────────────────────────────
 *
 * The partner ceiling is slice 5. Partners hold no grants yet, and
 * `partners.entitlement_mode` defaults to `unrestricted`, so intersecting now
 * would be intersecting with nothing — which is either a no-op or a total
 * outage depending on which reading you pick, and picking is the point of R-1.
 */
class EntitlementResolver
{
    /**
     * Request-scoped memo. Keyed by workspace id.
     *
     * Not a cache: it lives for one request and is never written anywhere. It
     * exists because `EnforceLimit`, the Inertia middleware and a controller can
     * each ask within the same request, and three identical folds is waste, not
     * correctness. Anything that outlives the request belongs to the
     * materialized read model, with the invalidation story that requires.
     *
     * @var array<int, Entitlement>
     */
    private array $memo = [];

    public function __construct(
        private readonly PlanPackageSynthesizer $synthesizer = new PlanPackageSynthesizer,
    ) {}

    public function for(int $workspaceId): Entitlement
    {
        return $this->memo[$workspaceId] ??= $this->resolve($workspaceId);
    }

    /** Drops the memo. For tests and for long-running workers between jobs. */
    public function flush(): void
    {
        $this->memo = [];
    }

    /**
     * The client-level entry point.
     *
     * Entitlements are held by an organisation and consumed by its workspaces,
     * so this is the real resolution unit; `for()` is the workspace-shaped
     * convenience over it. Both funnel into ONE `bundlesFor()`, which is the
     * only place that decides which subscription is in effect — the duplication
     * of that decision is precisely what BUG-023 was.
     */
    public function forClient(?Client $client): Entitlement
    {
        return $this->fold($this->bundlesFor($client));
    }

    private function resolve(int $workspaceId): Entitlement
    {
        return $this->forClient(Workspace::with('client')->find($workspaceId)?->client);
    }

    /**
     * Everything this client holds, in fold shape.
     *
     * ⚠️ The plan source mirrors `EnforceLimit` EXACTLY, flag and all. Two
     * places deciding "which subscription is in effect" is how BUG-023 happened
     * — `EnforceLimit` used `activePlan()` while the dashboard used
     * `effectiveSubscription()`, and every gateway-billed customer fell through
     * the gap. Slice 3 removes the duplication by making the middleware call
     * this class; until then they must agree by construction, not by luck.
     *
     * @return list<GrantBundle>
     */
    private function bundlesFor(?Client $client): array
    {
        if (! $client) {
            return [];
        }

        $plan = config('entitlements.enforce_effective_plan_source', false)
            ? $client->effectivePlan()
            : $client->activePlan();

        $bundle = $this->synthesizer->forPlan($plan);

        return $bundle ? [$bundle] : [];
    }

    /**
     * @param  list<GrantBundle>  $bundles
     */
    public function fold(array $bundles): Entitlement
    {
        /** @var array<string, int|null> $limits */
        $limits = [];
        /** @var array<string, bool> $flags */
        $flags = [];

        // ── The ONE match. Partitions by type and nothing else. ─────────────
        //
        // An unknown type REFUSES rather than falling through. A bundle the fold
        // silently ignores is a holding the customer paid for and did not
        // receive — and it would look identical to not holding it at all.
        /** @var array<string, list<GrantBundle>> $byType */
        $byType = [AddOn::TYPE_PACKAGE => [], AddOn::TYPE_PACK => [], AddOn::TYPE_FEATURE => []];

        foreach ($bundles as $bundle) {
            $byType[match ($bundle->type) {
                AddOn::TYPE_PACKAGE => AddOn::TYPE_PACKAGE,
                AddOn::TYPE_PACK => AddOn::TYPE_PACK,
                AddOn::TYPE_FEATURE => AddOn::TYPE_FEATURE,
                default => throw new \UnexpectedValueException(
                    "Unknown add-on type '{$bundle->type}'. A type the fold does not understand "
                    .'would be silently dropped — a holding the customer has and does not get.'
                ),
            }][] = $bundle;
        }

        // ── 1. DOMINANT: the single highest-ranked package, never summed ─────
        $packages = $byType[AddOn::TYPE_PACKAGE];

        if ($packages !== []) {
            usort($packages, fn (GrantBundle $a, GrantBundle $b) => $b->rank <=> $a->rank);
            $limits = $packages[0]->grants;
        }

        // ── 2. ADDITIVE: packs stack onto whatever the package established ───
        foreach ($byType[AddOn::TYPE_PACK] as $bundle) {
            foreach ($bundle->grants as $key => $value) {
                // Unlimited dominates a number in EITHER direction: an unlimited
                // base cannot be made finite by adding to it, and an unlimited
                // pack makes a finite base unlimited.
                if (array_key_exists($key, $limits) && $limits[$key] === null) {
                    continue;
                }

                if ($value === null) {
                    $limits[$key] = null;

                    continue;
                }

                // Absent is not zero — a pack may grant a key the package never
                // mentioned, and that must land as the pack's own value rather
                // than be folded into a base that does not exist.
                $limits[$key] = ($limits[$key] ?? 0) + ($value * $bundle->quantity);
            }
        }

        // ── 3. BOOLEAN: OR ──────────────────────────────────────────────────
        foreach ($byType[AddOn::TYPE_FEATURE] as $bundle) {
            foreach (array_keys($bundle->grants) as $key) {
                $flags[$key] = true;
            }
        }

        return new Entitlement($limits, $flags);
    }
}
