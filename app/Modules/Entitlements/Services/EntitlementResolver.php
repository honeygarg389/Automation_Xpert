<?php

namespace App\Modules\Entitlements\Services;

use App\Models\Client;
use App\Models\Partner;
use App\Models\Workspace;
use App\Modules\Entitlements\Models\AddOn;
use App\Modules\Entitlements\Support\Entitlement;
use App\Modules\Entitlements\Support\GrantBundle;
use Illuminate\Support\Facades\Log;

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
        private readonly CatalogBundleBuilder $catalog = new CatalogBundleBuilder,
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
        $customer = $this->fold($this->bundlesFor($client));

        return $this->applyCeiling($customer, $client?->partner);
    }

    /**
     * ⚠️ THE PARTNER CEILING. Rule 6: a reseller cannot sell what it does not
     * hold, so the customer's entitlement is intersected with its partner's.
     *
     * Applied AFTER the fold, never inside it. The fold answers "what did this
     * customer buy"; the ceiling answers "what is their reseller permitted to
     * sell". Folding them together would make a partner grant a bundle — and a
     * bundle can be SUMMED with the customer's plan, which is the exact opposite
     * of capping. The dominant/additive split already guards that door; this
     * would have opened a side one.
     *
     * ─── The three states, and only one of them intersects ──────────────────
     *
     *   no partner (direct customer)  -> returns the SAME instance, untouched
     *   partner, unrestricted mode    -> returns the SAME instance, untouched
     *   partner, ceiling mode         -> intersected
     *
     * Returning the identical object for the first two is deliberate: a test can
     * assert `assertSame`, which a ceiling that merely happens to be a no-op
     * cannot satisfy. With no partner grants an intersection looks exactly like
     * skipping one, and that is the vacuity trap here.
     */
    public function applyCeiling(Entitlement $customer, ?Partner $partner): Entitlement
    {
        if ($partner === null || ! $partner->hasCeiling()) {
            return $customer;
        }

        $bundles = $this->catalog->forPartner($partner);

        // ⚠️ THE READ-TIME INVARIANT.
        //
        // `Partner::assertCeilingIsConfigured()` refuses to SAVE a partner into
        // ceiling mode without grants. It cannot hold the invariant on its own,
        // and not merely because someone might delete the grants afterwards
        // without touching the partner row — a grant LAPSES BY DATE. `ends_at`
        // passing is not a write to anything, so no model hook anywhere can
        // observe it. Read time is the only place both sides are visible.
        //
        // The answer here must never be "unlimited". A ceiling that grants
        // nothing IS the correct reading of an empty ceiling in ceiling mode, it
        // fails CLOSED, and it is bounded: only entitlement-gated actions refuse,
        // rather than every page 500ing as an exception would cause. Logged
        // loudly because it is a misconfiguration, not a normal state.
        if ($bundles === []) {
            Log::channel('entitlements')->warning(
                'entitlements.ceiling_empty: partner is in ceiling mode with no grants in force',
                [
                    'partner_id' => $partner->id,
                    'partner_slug' => $partner->slug,
                    'effect' => 'customers of this partner are entitled to nothing until a grant is in force',
                ]
            );

            return new Entitlement;
        }

        return $this->intersect($customer, $this->fold($bundles));
    }

    /**
     * Per key: min() for numbers, AND for booleans.
     *
     * ⚠️ NOT php's `min()`. `min(null, 5)` returns null, and null reads as
     * UNLIMITED — so the built-in would turn a 5-message ceiling into no ceiling
     * at all. Every comparison here is explicit for that reason.
     *
     * Key semantics, per the ruling:
     *
     *   ceiling value null  -> the partner explicitly holds this unlimited, so
     *                          it does not cap: the customer keeps their value
     *   ceiling key absent  -> the partner does not hold this at all, so they
     *                          cannot resell it: the customer gets nothing
     */
    private function intersect(Entitlement $customer, Entitlement $ceiling): Entitlement
    {
        $ceilingLimits = $ceiling->limits();
        $out = [];

        foreach ($customer->limits() as $key => $customerValue) {
            if (! array_key_exists($key, $ceilingLimits)) {
                // Absent from the ceiling: not resellable. Dropped entirely
                // rather than set to 0, so has() reports it as ungranted — which
                // is what it is.
                continue;
            }

            $ceilingValue = $ceilingLimits[$key];

            $out[$key] = match (true) {
                $ceilingValue === null => $customerValue,   // partner unlimited: no cap
                $customerValue === null => $ceilingValue,   // customer unlimited: capped by partner
                default => min($customerValue, $ceilingValue),
            };
        }

        $flags = [];
        foreach ($customer->flags() as $key => $on) {
            $flags[$key] = $on && $ceiling->allows($key);   // AND
        }

        return new Entitlement($out, $flags);
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

            // A package may carry boolean features too — today only the legacy
            // white_label column, bridged in by PlanPackageSynthesizer. Dominant
            // like the rest of the package: the winner's flags, not a merge.
            foreach ($packages[0]->flags as $key => $on) {
                if ($on) {
                    $flags[$key] = true;
                }
            }
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
