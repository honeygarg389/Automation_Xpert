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
 * `User` is infrastructure, not tenant data, and it is the only table whose
 * `workspace_id` is NULLABLE.
 *
 * The failure is immediate and total, and it was measured rather than reasoned
 * about. With the trait on `User` and no authenticated context:
 *
 *     User::find($id)                    => null
 *     User::where('email', $e)->first()  => null   <- LOGIN CANNOT FIND THE USER
 *
 * Because the scope fails closed, and a login lookup by definition happens
 * before anyone is authenticated, scoping `User` locks every account out of the
 * application. The workspace switcher and `accessibleWorkspaces()` go with it.
 *
 * (An earlier draft of this comment claimed the real danger was infinite
 * recursion through `WorkspaceContext -> accessibleWorkspaces -> Workspace`.
 * That was tested and is NOT what happens — the resolution path completes.
 * The lockout above is the actual failure, and it is worse.)
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
