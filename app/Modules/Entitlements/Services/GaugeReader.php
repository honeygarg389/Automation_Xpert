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
        $source = GaugeSources::for($key);

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

        return match ($source['scope']) {
            GaugeSources::SCOPE_CLIENT => $workspace->client_id === null
                ? 0
                : (int) $query->where('client_id', $workspace->client_id)->count(),
            default => (int) $query->where('workspace_id', $workspace->id)->count(),
        };
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
