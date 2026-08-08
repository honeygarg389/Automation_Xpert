<?php

namespace App\Modules\Broadcasting\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Per-workspace, per-period usage counter. `(workspace_id, metric, period)` is
 * unique; `period` is `Ym`.
 *
 * ─── ⚠️ THIS COUNTER DID NOT ACCUMULATE, AND NEVER HAD ──────────────────────
 *
 * `track()` used to be:
 *
 *     static::updateOrCreate(
 *         ['workspace_id' => …, 'metric' => …, 'period' => …],
 *         ['value' => 0]          // ← on an EXISTING row this UPDATES value to 0
 *     );
 *     static::where(…)->increment('value', $by);
 *
 * `updateOrCreate`'s second argument is applied on update as well as on insert,
 * so every call reset the counter to zero and then incremented it. Measured:
 * three consecutive `track()` calls left `current()` reading 1, and two 500-token
 * calls left it reading 500.
 *
 * The consequence was not "slightly low numbers". `EnforceLimit` compares
 * `$usage >= $limit`, so it was comparing 1 against the plan limit — false for
 * every limit above 1. **No plan limit was enforced anywhere, for any customer,
 * for as long as this method has existed.** See BUG-022.
 *
 * ─── Why the query builder rather than Eloquent ─────────────────────────────
 *
 * The replacement is ONE statement (`ON DUPLICATE KEY UPDATE value = value + n`),
 * not two. The old shape had a second defect behind the first: read-then-write
 * across two statements races, and `track()` is called from queue workers —
 * `SendCampaignMessageJob` calls it once per delivered message, in parallel. Two
 * workers interleaving between the upsert and the increment lose a count. The
 * atomic form cannot.
 */
class UsageMeter extends Model
{
    use BelongsToWorkspace;

    protected $table = 'usage_meters';

    protected $fillable = ['workspace_id', 'metric', 'period', 'value'];

    protected function casts(): array
    {
        return ['value' => 'integer', 'period' => 'integer'];
    }

    /** The current billing period, as `Ym`. */
    public static function period(): int
    {
        return (int) now()->format('Ym');
    }

    /**
     * Add `$by` to this workspace's counter for `$metric` in the current period.
     *
     * Atomic and idempotent-safe under concurrency: a single INSERT … ON
     * DUPLICATE KEY UPDATE against the `(workspace_id, metric, period)` unique
     * index. There is no read-modify-write window for a parallel worker to land
     * in.
     */
    public static function track(int $workspaceId, string $metric, int $by = 1): void
    {
        $now = now();

        DB::table('usage_meters')->upsert(
            [[
                'workspace_id' => $workspaceId,
                'metric' => $metric,
                'period' => static::period(),
                'value' => $by,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['workspace_id', 'metric', 'period'],
            ['value' => DB::raw('value + '.(int) $by), 'updated_at' => $now]
        );
    }

    /**
     * This workspace's usage of `$metric` in the current period.
     *
     * ⚠️ DELIBERATELY BYPASSES THE WORKSPACE SCOPE. The caller names the
     * workspace explicitly and the method's own `where('workspace_id', …)` IS
     * the tenant boundary — the global scope on top of it is redundant when the
     * context matches and actively dangerous when it does not.
     *
     * This is hazard H-2 with the failure direction that matters: the scope
     * fails closed, so a null context would AND an unsatisfiable condition onto
     * the query, return 0, and `EnforceLimit` would read "no usage" — which it
     * treats as under-limit. A missing workspace context would silently grant
     * unlimited quota. Bypassing cannot fail that way; it either answers
     * correctly or not at all.
     */
    public static function current(int $workspaceId, string $metric): int
    {
        return (int) static::withoutWorkspaceScope(
            'reason: the explicit workspace_id argument IS the boundary; the scope '
            .'failing closed here would report zero usage and grant unlimited quota'
        )
            ->where('workspace_id', $workspaceId)
            ->where('metric', $metric)
            ->where('period', static::period())
            ->value('value');
    }
}
