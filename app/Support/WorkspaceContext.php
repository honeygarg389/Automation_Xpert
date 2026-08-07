<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * The single source of truth for "which workspace is this request operating in".
 *
 * ─── Why this exists ────────────────────────────────────────────────────────
 *
 * The codebase currently resolves the active workspace in ~90 places with:
 *
 *     $user->current_workspace_id ?? $user->workspace_id
 *
 * `users.current_workspace_id` does not exist — not a column, not an accessor,
 * not a cast. Every one of those expressions therefore silently yields the
 * user's HOME workspace. Meanwhile WorkspaceController writes the switched
 * workspace to the session, and HandleInertiaRequests reads it — so the UI
 * shows workspace B while every controller operates on workspace A.
 *
 * See docs/phase-0-tenant-isolation-plan.md §G-1.
 *
 * ─── Resolution order ───────────────────────────────────────────────────────
 *
 *   1. An explicit override set by for() — jobs, console commands, webhooks
 *   2. The session's current_workspace_id, ONLY if the user is a member
 *   3. The user's home workspace_id
 *   4. null
 *
 * Step 2's membership check is not optional. The session is client-controlled
 * input; trusting it without verification would turn workspace switching into a
 * cross-tenant read the moment controllers start honouring it.
 *
 * ─── Status ─────────────────────────────────────────────────────────────────
 *
 * Commit 1a wires this into NOTHING. It is introduced with tests first so the
 * call-site migration (1b, 1c) has something already proven to migrate to.
 */
class WorkspaceContext
{
    /** Explicit override, set by for(). Highest precedence. */
    private static ?int $override = null;

    /**
     * True while a deliberately cross-tenant operation is running.
     *
     * Phase 0, slice 4. The schedulers exist to scan EVERY workspace for due
     * work, and the workspace scope fails closed — so "no context" gives them
     * nothing, not everything. Skipping context establishment is not enough;
     * the scope has to be told this is intentional.
     *
     * Set only by crossTenant(), which is time-bounded to one callable and
     * counted by the bypass inventory. It is a door, not a default.
     */
    private static bool $crossTenant = false;

    /**
     * Memoised resolution, keyed by user id, so a global scope calling id()
     * once per query does not re-run the membership check every time.
     *
     * @var array<int, int|null>
     */
    private static array $resolved = [];

    /**
     * Run a callback with an explicit workspace, then restore the previous one.
     *
     * The sanctioned way for queued jobs, console commands and webhook handlers
     * to establish tenant context — none of them have an authenticated user.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function for(int $workspaceId, callable $callback): mixed
    {
        $previous = self::$override;
        self::$override = $workspaceId;

        try {
            return $callback();
        } finally {
            self::$override = $previous;
        }
    }

    /**
     * Run a callback across ALL workspaces, with the scope suppressed.
     *
     * For operations whose correctness REQUIRES seeing every tenant: the
     * campaign/post schedulers, and OAuth token refresh. Giving those a single
     * workspace silently reduces them to one tenant's work; giving them no
     * context at all gives them nothing, because the scope fails closed.
     *
     * The reason is required and is not decoration — `WorkspaceContext::crossTenant`
     * is one of the spellings the bypass-inventory guard greps for, so every use
     * is counted and reviewed.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function crossTenant(string $reason, callable $callback): mixed
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException(
                'crossTenant() requires a reason: an unexplained cross-tenant read is indistinguishable from a leak.'
            );
        }

        $previous = self::$crossTenant;
        self::$crossTenant = true;

        try {
            return $callback();
        } finally {
            self::$crossTenant = $previous;
        }
    }

    /** Whether a deliberately cross-tenant operation is in progress. */
    public static function isCrossTenant(): bool
    {
        return self::$crossTenant;
    }

    /**
     * The workspace id this request/job is operating in, or null when there is
     * no context (unauthenticated request, or a job that did not set one).
     *
     * Callers that must not proceed without context should check for null
     * explicitly. A throwing variant is deliberately deferred to the commit
     * that first needs it.
     */
    public static function id(): ?int
    {
        if (self::$override !== null) {
            return self::$override;
        }

        $user = Auth::user();

        if (! $user instanceof User) {
            // Admin guard, guest, or a queued job: no workspace context.
            return null;
        }

        if (array_key_exists($user->id, self::$resolved)) {
            return self::$resolved[$user->id];
        }

        return self::$resolved[$user->id] = self::resolveForUser($user);
    }

    /** True when a workspace context can be established. */
    public static function has(): bool
    {
        return self::id() !== null;
    }

    /**
     * Whether the user may operate in the given workspace.
     *
     * Delegates to the existing accessibleWorkspaces() so there is one
     * definition of membership rather than a competing second one.
     */
    public static function userCanAccess(User $user, int $workspaceId): bool
    {
        return $user->accessibleWorkspaces()->contains('id', $workspaceId);
    }

    /**
     * Forget memoised resolutions. Call after changing a user's membership, and
     * between tests.
     */
    public static function flush(): void
    {
        self::$override = null;
        self::$resolved = [];
        self::$crossTenant = false;
    }

    private static function resolveForUser(User $user): ?int
    {
        $sessionWorkspaceId = self::sessionWorkspaceId();

        if ($sessionWorkspaceId !== null && ! self::userCanAccess($user, $sessionWorkspaceId)) {
            // Resolution time is not switch time. There is no user action to
            // refuse here — this fires on whatever page happens to load next,
            // so erroring the request would lock the user out over a stale
            // session value. Instead: record it, discard the bad value so the
            // session self-heals and does not log on every subsequent request,
            // and fall back to the home workspace.
            //
            // An explicit switch attempt IS refused with a message — see
            // WorkspaceController::switch().
            Log::warning('workspace.context.session_rejected', [
                'user_id' => $user->id,
                'user_client_id' => $user->client_id,
                'rejected_workspace_id' => $sessionWorkspaceId,
                'fell_back_to' => $user->workspace_id,
            ]);

            self::forgetSessionWorkspace();

            $sessionWorkspaceId = null;
        }

        if ($sessionWorkspaceId !== null) {
            return $sessionWorkspaceId;
        }

        return $user->workspace_id !== null ? (int) $user->workspace_id : null;
    }

    private static function forgetSessionWorkspace(): void
    {
        $request = request();

        if ($request->hasSession()) {
            $request->session()->forget('current_workspace_id');
        }
    }

    private static function sessionWorkspaceId(): ?int
    {
        $request = request();

        if (! $request->hasSession()) {
            return null;
        }

        $value = $request->session()->get('current_workspace_id');

        return is_numeric($value) ? (int) $value : null;
    }
}
