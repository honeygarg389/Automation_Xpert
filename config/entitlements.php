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
    */

    'enforce_effective_plan_source' => env('ENFORCE_EFFECTIVE_PLAN_SOURCE', false),

];
