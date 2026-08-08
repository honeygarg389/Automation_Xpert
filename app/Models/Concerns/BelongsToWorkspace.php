<?php

namespace App\Models\Concerns;

use App\Models\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Builder;

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
 *
 * ─── Why this trait does NOT define a workspace() relation ───────────────────
 *
 * An earlier draft did. It was removed at slice 5, deliberately.
 *
 * `InboxLabel` and `CannedReply` already declare their own `workspace()`, and
 * PHP resolves class-over-trait SILENTLY — no error, no warning. That is the
 * "one concept, two definitions" shape that has already produced two bugs in
 * this codebase (`accessibleWorkspaces()` vs `isAccessibleBy()`; the two
 * WhatsApp dedupe layers), both found only because a test failed for an
 * unexpected reason.
 *
 * Three options were on the table: drop it from the trait, delete the two
 * duplicates, or keep both behind a guard test. The deciding fact is that
 * **nothing among the 27 models uses a `workspace` relation at all** — measured,
 * zero call sites in `app/`, `resources/` and `tests/`. Every `->workspace` hit
 * in the codebase is `$user->workspace`, and `User` never takes this trait.
 *
 * So a guard test would police a collision that need not exist, and deleting
 * the two existing definitions would touch the Inbox module from a scope commit
 * for no benefit. Removing it here eliminates the collision outright and leaves
 * the trait doing exactly one thing: applying the scope.
 *
 * If a scoped model ever needs the relation, it declares its own — one
 * definition, in the place that uses it.
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
     * @param  Builder<static>  $query
     * @param  string  $reason  Why this query must cross workspaces. Required.
     *                          Conventionally prefixed "reason: ".
     * @return Builder<static>
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
}
