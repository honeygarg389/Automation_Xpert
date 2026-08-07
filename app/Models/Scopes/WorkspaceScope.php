<?php

namespace App\Models\Scopes;

use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 0. Constrains every query on a workspace-owned model to the workspace
 * the current request, job or command is operating in.
 *
 * ─── Why this exists ────────────────────────────────────────────────────────
 *
 * Phase 1c fixed 68 controller sites BY HAND. That works exactly as long as
 * every future author remembers, at every future site. This makes it a property
 * of the model rather than a property of the author's attention: the database
 * filters correctly regardless of what a controller passes.
 *
 * ─── Reads only ─────────────────────────────────────────────────────────────
 *
 * This scope FILTERS. It does NOT write `workspace_id` on create. Ruled
 * 2026-08-07: 11 of 17 factories do not set the column, jobs and webhooks have
 * no context at create time, and 68 controller sites already write it
 * explicitly — auto-fill would either conflict with those or make them
 * redundant, invisibly. Read filtering is enforcement; write auto-fill is a
 * behaviour change. See docs/found-bugs.md.
 *
 * ─── Null context fails CLOSED ──────────────────────────────────────────────
 *
 * When no workspace can be resolved, this matches NOTHING rather than matching
 * everything. Ruled 2026-08-07.
 *
 * The alternative — "no context, no filter" — is the common Laravel pattern and
 * it is wrong here: it would make the scope a no-op in precisely the paths that
 * carry the most risk, because those are the paths with no authenticated user.
 * An unauthenticated request, a job that forgot to establish context, and a
 * webhook handler would all see every tenant's rows. A scope that protects only
 * the code that was already protecting itself is decoration.
 *
 * The cost is that a job which does not establish context returns nothing and
 * looks broken. That is the intended trade: visibly broken beats silently
 * cross-tenant.
 *
 * ─── The admin exception is a DOOR, not an oversight ────────────────────────
 *
 * The admin panel legitimately reads across tenants — that is its purpose. So
 * an authenticated `admin` guard session bypasses the filter entirely.
 *
 * This is a deliberate, ruled decision, and it is load-bearing: it is what keeps
 * `Admin\DashboardController`'s platform-wide counts correct (hazard H-1). It is
 * also the widest hole in the isolation story, so it is pinned by its own test —
 * `the_admin_guard_is_a_deliberate_door_out_of_the_scope` — named so that anyone
 * auditing this later sees a decision rather than a gap.
 *
 * It is safe only because the admin guard is a separate authentication system
 * with its own DB-backed RBAC. A customer cannot reach it.
 */
class WorkspaceScope implements Scope
{
    /** The bypass name, so callers can drop this scope by string as well as class. */
    public const IDENTIFIER = 'workspace';

    public function apply(Builder $builder, Model $model): void
    {
        // The emergency brake. Server config only — see config/workspace.php for
        // why it is deliberately unreachable from the admin panel.
        //
        // Read at query time rather than captured at boot, so clearing the config
        // cache is enough to take effect. Defaults to ON: a brake whose default
        // is "off" is an isolation feature that ships disabled.
        if (! config('workspace.enforce_scope', true)) {
            return;
        }

        // The admin panel reads across tenants by design. See the class docblock:
        // this is a ruled exception with a named test, not an omission.
        if (Auth::guard('admin')->check()) {
            return;
        }

        $workspaceId = WorkspaceContext::id();

        if ($workspaceId === null) {
            // Fail closed. Not `return` — see the class docblock.
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->getTable().'.workspace_id', $workspaceId);
    }
}
