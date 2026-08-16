<?php

namespace App\Modules\Entitlements\Services;

use App\Models\Workspace;
use App\Modules\Entitlements\Support\GaugeSources;
use Illuminate\Database\Eloquent\Model;

/**
 * Counts what a workspace (or its client) currently HOLDS, for gauge limits.
 *
 * Wired to nothing in this slice. It measures; it does not decide, refuse, or
 * log. Keeping it inert is the point — a reader with no callers can be verified
 * against real rows without any behaviour riding on the answer.
 */
class GaugeReader
{
    /**
     * How many of `$key` this workspace currently holds, or null if `$key` is
     * not a gauge with a declared source.
     *
     * ⚠️ DELIBERATELY BYPASSES THE WORKSPACE SCOPE, and this is the direction
     * that matters. The scope fails CLOSED, so a count run without a workspace
     * context would return 0 — and 0 reads as "nothing held", which grants the
     * FULL limit as headroom. A gauge that under-counts hands out unlimited
     * capacity, exactly as `ContactCapacity` did and `UsageMeter::current()`
     * would have.
     *
     * The explicit `$workspaceId`/`client_id` argument IS the tenant boundary
     * here, and it is applied unconditionally below. Six of the seven models are
     * still in Phase 0's un-started slice 8; bypassing from birth means slice 8
     * cannot later convert these counts to fail-open underneath us.
     */
    public function count(string $key, Workspace $workspace): ?int
    {
        $source = $this->sourceFor($key);

        if ($source === null) {
            return null;
        }

        /** @var class-string<Model> $class */
        $class = $source['model'];

        $query = $class::query();

        // A model that has the trait must be taken out from under it; one that
        // never had it (User) has no scope to remove.
        if (method_exists($class, 'scopeWithoutWorkspaceScope')) {
            $query = $class::withoutWorkspaceScope(
                'reason: the explicit tenant column below IS the boundary; the scope failing '
                .'closed would count ZERO and grant the full limit as headroom'
            );
        }

        // ⚠️ THE FILTER, guarded on the KEY'S ABSENCE — not on truthiness.
        //
        // `isset()` is false for a missing key AND for an explicit
        // `'where' => null`, which is the behaviour R-7 requires: a null filter
        // must not be treated as "no filter". `array_key_exists` would fire the
        // branch for null and conflate absent with null — the BUG-030 shape.
        //
        // Only `smart_qr_max_assigned` declares one today; the other seven
        // entries carry exactly `model` and `scope`, so this loop cannot execute
        // for them. That is what made the change safe to land inside a Smart QR
        // slice rather than on its own branch.
        if (isset($source['where'])) {
            foreach ($source['where'] as $column => $value) {
                // null means IS NULL. `where($col, null)` builds `= NULL`, which
                // matches nothing in SQL — a gauge silently returning 0, and 0
                // reads as "nothing held", which grants the full limit.
                $query = $value === null
                    ? $query->whereNull($column)
                    : $query->where($column, $value);
            }
        }

        return match ($source['scope']) {
            GaugeSources::SCOPE_CLIENT => $workspace->client_id === null
                ? 0
                : (int) $query->where('client_id', $workspace->client_id)->count(),
            default => (int) $query->where('workspace_id', $workspace->id)->count(),
        };
    }

    /**
     * Where a gauge's declaration comes from.
     *
     * ⚠️ A SEAM, and it exists because R-7's second condition could not be met
     * without one. That condition requires the "the seven are unmoved" test to
     * DISCRIMINATE — to add a `where` to one of the unfiltered gauges, prove its
     * count changes, remove it, and prove it returns. `GaugeSources::MAP` is a
     * `const` on a `final` class, so nothing can mutate it and no double can
     * replace it while this method calls the static directly.
     *
     * The alternative was a test asserting only that the seven are unchanged,
     * which passes trivially against untouched code — the vacuous shape this
     * project has caught repeatedly, and the exact shape condition 2 exists to
     * forbid.
     *
     * So the indirection is one line of production code shaped by a test,
     * deliberately. It changes no behaviour: the default is the static.
     *
     * @return array{model: class-string, scope: string, where?: array<string, mixed>}|null
     */
    protected function sourceFor(string $key): ?array
    {
        return GaugeSources::for($key);
    }

    /**
     * Every enforceable gauge for this workspace, key => current count.
     *
     * @return array<string, int>
     */
    public function all(Workspace $workspace): array
    {
        $out = [];

        foreach (GaugeSources::enforceableKeys() as $key) {
            $out[$key] = (int) $this->count($key, $workspace);
        }

        return $out;
    }
}
