<?php

namespace App\Models\Scopes;

use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

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
 * The admin panel legitimately reads across tenants — that is its purpose. So a
 * request genuinely served by the admin panel bypasses the filter entirely.
 *
 * This is a deliberate, ruled decision, and it is load-bearing: it is what keeps
 * `Admin\DashboardController`'s platform-wide counts correct (hazard H-1). It is
 * also the widest hole in the isolation story, so it is pinned by its own test —
 * `the_admin_guard_is_a_deliberate_door_out_of_the_scope` — named so that anyone
 * auditing this later sees a decision rather than a gap.
 *
 * ⚠️ THE DOOR MUST BE ROUTE-AWARE, NOT GUARD-STATE-AWARE — CONFIRMED CROSS-TENANT
 * LEAK, 2026-09-15. This used to be `Auth::guard('admin')->check()`. That is
 * FALSE SAFETY: `Admin\ClientController::impersonate()` logs the `web` guard in
 * as the target client's user but deliberately never logs the `admin` guard
 * out — `ImpersonationController::stop()` only logs out `web` too — so BOTH
 * guards stay authenticated for the entire impersonation session, by design
 * (that is what makes "Return to Admin" work afterwards). A guard-state check
 * cannot tell "a genuine admin-panel request" apart from "an admin impersonating
 * a client and browsing that client's own workspace-scoped pages" — both have
 * `Auth::guard('admin')->check() === true`.
 *
 * Measured directly: impersonate SpaGreen Wellness (workspace 2), then load the
 * client-facing Flows index (`WhatsappFlow::query()->get()`, reached via the
 * `web`-guarded `client-app` middleware, not `/admin/*`). `WorkspaceContext::id()`
 * correctly resolved to 2 — the context resolution was never the bug — but the
 * guard-state check fired anyway and returned every workspace's rows: 2 flows
 * belonging to Demo Client (workspace 1) leaked alongside SpaGreen's own 1 flow.
 * The same defect applies to any client-facing page, for all 27 models carrying
 * this trait, for the whole duration of any impersonation session.
 *
 * The fix checks whether the CURRENT ROUTE actually sits behind the admin
 * panel's `auth:admin` middleware — the real authorization boundary — rather
 * than trusting a guard flag that impersonation intentionally leaves set. This
 * mirrors `HandleInertiaRequests::share()`'s existing `$isAdminRoute` computation
 * (route-name based there), which already got this right; do not revert to a
 * bare guard check no matter how convenient it looks — that is exactly this bug.
 *
 * It is safe because `auth:admin` is a separate authentication system with its
 * own DB-backed RBAC, and because the check is against the resolved ROUTE
 * (impossible for a client request to spoof), not a session flag.
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

        // A deliberately cross-tenant operation — the schedulers, which must scan
        // every workspace for due work. Declared per call via
        // WorkspaceContext::crossTenant() and counted by the bypass inventory.
        //
        // This is NOT the same as "no context". No context fails closed and
        // returns nothing; this returns everything, on purpose.
        if (WorkspaceContext::isCrossTenant()) {
            return;
        }

        // The admin panel reads across tenants by design. See the class docblock:
        // this is a ruled exception with a named test, not an omission — and see
        // the "MUST BE ROUTE-AWARE" section there for why this checks the route's
        // middleware rather than `Auth::guard('admin')->check()`.
        if (self::requestIsBehindAdminPanel()) {
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

    /**
     * True only when the CURRENT ROUTE is genuinely gated by the admin panel's
     * `auth:admin` middleware — the real authorization boundary — not merely
     * when the `admin` guard happens to be authenticated in the background.
     *
     * Deliberately NOT a route-name-prefix check (`routeIs('admin.*')`): two
     * routes are named `admin.*` but sit outside `auth:admin` — `admin.login`
     * (`web, redirect.if.admin`) and `admin.impersonation.stop` (`web, auth` —
     * deliberately callable by the impersonated user on the `web` guard alone,
     * see its own route comment in bootstrap/app.php). `admin.logout` is named
     * `admin.*` too and happens to also carry `auth:admin`, so a prefix check
     * would not have been wrong for it specifically — but relying on that
     * holding by coincidence is the same shape of mistake this bug already
     * was. Matching by middleware sidesteps having to keep a list like this in
     * sync with routing changes; matching by name would silently reopen this
     * exact bug the day someone adds a new `admin.*`-named route that isn't
     * behind `auth:admin`.
     *
     * No route match (console command, queued job, artisan tinker) yields
     * false here, exactly as a plain guard check would have — this changes
     * nothing for non-HTTP contexts.
     */
    private static function requestIsBehindAdminPanel(): bool
    {
        $route = request()->route();

        return $route !== null && in_array('auth:admin', $route->gatherMiddleware(), true);
    }
}
