<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enforce against the EFFECTIVE plan source
    |--------------------------------------------------------------------------
    |
    | `EnforceLimit` has always resolved the plan through `Client::activePlan()`,
    | which reads `client_subscriptions` ONLY — the admin-assignment path, whose
    | single writer is Admin\ClientController::assignPlan.
    |
    | Every self-serve customer is billed through `subscriptions` instead (15
    | writers, one per gateway). For them `activePlan()` returns null, the
    | middleware reads an empty limits array, and `$limit === null` is treated as
    | unlimited. **Self-serve customers have never been subject to any plan
    | limit.** See BUG-023.
    |
    | `Client::effectivePlan()` is the correct source: admin assignment first,
    | otherwise the plan behind any of the client's users' active subscriptions.
    |
    | Flipping this is NOT a bug fix in the ordinary sense — it turns on
    | enforcement for a cohort that has never been metered, and the first thing
    | those customers will notice is a 402. So it ships DISABLED, and while
    | disabled the middleware still resolves the effective plan, still reads the
    | meter, and LOGS every request it would have blocked, to the
    | `entitlements` channel. Read those logs, size the cohort, decide, then flip
    | deliberately.
    |
    | false = report only (log the divergence, block nothing new)
    | true  = enforce against the effective plan
    |
    | ─── FLIPPED TO true, 2026-08-09 ───────────────────────────────────────
    |
    | The cohort this would start refusing was measured directly against the
    | working database, not inferred from the report-only log (which held only
    | test-run entries):
    |
    |     clients 0 · workspaces 0 · users 0
    |     subscriptions 0 · client_subscriptions 0 · usage_meters 0
    |
    | There is nobody to grandfather, because there is nobody. So the correct
    | source becomes the shipped default NOW, while the cohort is provably
    | empty. Deferring the flip to launch does not avoid the decision — it moves
    | it to the one moment when it is expensive, which is exactly the migration
    | this flag was built to make unnecessary.
    |
    | Report-only is NOT deleted. It stays as the fallback path: set the env var
    | to false and the middleware reverts to activePlan() and resumes logging
    | the divergence. That is the recovery route if the flip ever proves wrong.
    |
    */

    'enforce_effective_plan_source' => env('ENFORCE_EFFECTIVE_PLAN_SOURCE', true),

    /*
    |--------------------------------------------------------------------------
    | The entitlement facade — THE BRAKE
    |--------------------------------------------------------------------------
    |
    | Whether feature code asks `Entitlements` or reads `plans.limits` directly.
    |
    | Same shape as ENFORCE_WORKSPACE_SCOPE, and for the same reason: Phase 0
    | proved that brake worth having, and proved it by PULLING it against a real
    | model rather than a fixture. When it was pulled on `Contact`, route-model
    | binding started resolving foreign rows again and the controllers' own
    | checks held — which is the only reason we know the two layers are
    | independent. A brake nobody has pulled on the real thing is a brake nobody
    | knows works.
    |
    | true  = the three enforcing sites resolve through EntitlementResolver
    | false = they read plans.limits exactly as they did before Phase 1
    |
    | The legacy path is NOT dead code to be deleted once this settles. It is the
    | recovery path, and it stays until `plans.limits` itself is retired.
    |
    */

    'enabled' => env('ENTITLEMENTS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | The materialized read model — ITS OWN BRAKE
    |--------------------------------------------------------------------------
    |
    | Deliberately separate from `enabled`, because the two answer different
    | questions. ENTITLEMENTS_ENABLED=false abandons the resolver entirely and
    | reverts to reading plans.limits; this flag keeps the resolver and skips the
    | table.
    |
    | Riding the existing flag would mean a cache-staleness incident forces the
    | whole entitlement layer off — discarding every correct answer along with
    | the stale ones. Wrong blast radius.
    |
    | With this false, Entitlements behaves exactly as it did before slice 7,
    | which the slice-2 canary already covers.
    |
    */

    'cache_enabled' => env('ENTITLEMENTS_CACHE_ENABLED', true),

    /*
    | Fallback lifetime, in minutes, for rows with NO computed boundary — an
    | entitlement with no expiring grant and no ending subscription. Those have
    | no moment at which they become wrong, so a flat window is honest there and
    | only there.
    */

    'cache_fallback_ttl_minutes' => (int) env('ENTITLEMENTS_CACHE_TTL_MINUTES', 60),

];
