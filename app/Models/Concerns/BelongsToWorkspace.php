<?php

namespace App\Models\Concerns;

use App\Models\Scopes\WorkspaceScope;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 0. Marks a model as workspace-owned and applies {@see WorkspaceScope}.
 *
 * Applied to models whose table has a `workspace_id` column AND whose rows are
 * customer-owned data. 27 models qualify.
 *
 * ⚠️ NEVER apply this to `User`, and never to `Workspace`.
 *
 * `User` is the only model with a NULLABLE `workspace_id`, and it is
 * infrastructure rather than tenant data: login, the workspace switcher and
 * `accessibleWorkspaces()` all query it. Worse, it would recurse —
 * `WorkspaceScope` calls `WorkspaceContext::id()`, which calls
 * `User::accessibleWorkspaces()`, which queries `Workspace`. Scoping either
 * model puts the scope inside its own resolution path.
 *
 * That is not left to this comment: `WorkspaceScopeTest` asserts `User` does not
 * use this trait, and explains why in the failure message.
 *
 * ─── Bypassing ──────────────────────────────────────────────────────────────
 *
 * Use `withoutWorkspaceScope('reason: …')`. The reason argument is REQUIRED, by
 * signature — a rule that depends on remembering to write a comment is not a
 * rule. CI greps for this name AND for the native `withoutGlobalScope`, so no
 * bypass can be added without appearing in the inventory.
 *
 * There are currently zero bypasses in `app/`. Every future one is a deliberate
 * act, and should read like one.
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope);
    }

    /**
     * Drop the workspace scope for this query.
     *
     * @param  string  $reason  Why this query must cross workspaces. Required.
     *                          Conventionally prefixed "reason: ".
     */
    public function scopeWithoutWorkspaceScope(Builder $query, string $reason): Builder
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException(
                'withoutWorkspaceScope() requires a non-empty reason. A bypass without a stated reason is indistinguishable from a mistake.'
            );
        }

        return $query->withoutGlobalScope(WorkspaceScope::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
