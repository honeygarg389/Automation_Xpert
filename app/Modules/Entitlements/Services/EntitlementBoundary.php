<?php

namespace App\Modules\Entitlements\Services;

use App\Models\Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⚠️ WHEN DOES THIS ANSWER BECOME WRONG?
 *
 * Three inputs change a workspace's entitlement with NO WRITE ANYWHERE, so no
 * event can ever fire for them and no listener can ever catch them:
 *
 *   1. a grant's `starts_at` passes   — a dormant grant becomes in force
 *   2. a grant's `ends_at` passes     — a grant lapses (the slice-5 finding)
 *   3. a subscription's `ends_at` passes, in either subscription table
 *
 * ─── Why `starts_at` is the one that defeats a freshness heuristic ──────────
 *
 * For the other two, "recompute if the cache is older than the newest input"
 * would eventually work. For `starts_at` it never does: the cache row is written
 * AFTER the grant row already exists, so the cache looks fresher than its input
 * while the grant sits dormant. `computed_at` is newer, the row is wrong, and no
 * comparison of timestamps-of-writes can tell.
 *
 * The only mechanism that catches all three is an ABSOLUTE boundary — the next
 * moment at which any input changes state — computed at write time and compared
 * at read time.
 *
 * ─── What is NOT here, and why ──────────────────────────────────────────────
 *
 * The `usage_meters` period rollover (`Ym`) is deliberately absent. It changes
 * USAGE, not entitlement. This cache holds limits and flags — the denominator —
 * while usage is the numerator and is read live by `QuotaGuard`/`UsageMeter`.
 * Verified: the resolver and `Entitlement` contain zero references to
 * `UsageMeter` or `period`. Including a boundary that can never change the
 * payload would make this class look as though it covered usage staleness, which
 * it does not and must not be relied upon to.
 */
class EntitlementBoundary
{
    /**
     * The earliest moment any dated input flips state, or null if none does.
     */
    public function forClient(?Client $client): ?Carbon
    {
        if ($client === null) {
            return null;
        }

        $now = now();
        $candidates = [];

        // 1 + 2: the client's own grants, and its partner's ceiling grants.
        // Both feed the resolved answer, so both bound it.
        $partnerId = $client->partner_id;

        $grants = DB::table('entitlement_grants')
            ->where('status', 'active')
            ->where(function ($q) use ($client, $partnerId) {
                $q->where('client_id', $client->id);
                if ($partnerId !== null) {
                    $q->orWhere('partner_id', $partnerId);
                }
            })
            ->get(['starts_at', 'ends_at']);

        foreach ($grants as $g) {
            // A start in the FUTURE is a boundary; one in the past has already
            // happened and bounds nothing.
            if ($g->starts_at !== null && Carbon::parse($g->starts_at)->greaterThan($now)) {
                $candidates[] = Carbon::parse($g->starts_at);
            }
            if ($g->ends_at !== null && Carbon::parse($g->ends_at)->greaterThan($now)) {
                $candidates[] = Carbon::parse($g->ends_at);
            }
        }

        // 3: both subscription tables. A subscription ending sooner than any
        // grant must win — the boundary is the EARLIEST, not the grant's alone.
        $subEnds = DB::table('client_subscriptions')
            ->where('client_id', $client->id)->where('status', 'active')
            ->whereNotNull('ends_at')->where('ends_at', '>', $now)
            ->min('ends_at');

        $userSubEnds = DB::table('subscriptions')
            ->whereIn('user_id', DB::table('users')->where('client_id', $client->id)->select('id'))
            ->whereIn('status', ['active', 'trialing'])
            ->whereNotNull('ends_at')->where('ends_at', '>', $now)
            ->min('ends_at');

        foreach ([$subEnds, $userSubEnds] as $t) {
            if ($t !== null) {
                $candidates[] = Carbon::parse($t);
            }
        }

        if ($candidates === []) {
            return null;
        }

        return collect($candidates)->sort()->first();
    }
}
