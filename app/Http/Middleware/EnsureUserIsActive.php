<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-006. Refuse a request whose token belongs to a deactivated user.
 *
 * `auth:sanctum` proves the token is valid. It does NOT re-check the account
 * behind it, and nothing else on the API stack did either — the middleware was
 * `auth:sanctum`, `throttle`, `demo`, `api.ability`, none of which look at
 * `users.status`. So deactivating a user blocked new logins
 * (MobileAuthController::login checks status) while every token already issued
 * kept working. That combination is the worst one available: it LOOKS handled.
 *
 * User::booted() now deletes tokens when an account is deactivated, which
 * handles it going forward. This middleware is the structural guarantee behind
 * that: it holds for tokens issued before the fix, for a status written by
 * anything that bypasses Eloquent events (a raw query, a bulk update, a future
 * import), and for the admin guard, none of which the model hook can see.
 *
 * 401 rather than 403: the credential is no longer valid, which is an
 * authentication answer, not a permission one. It also matches what the client
 * should do — discard the token and re-authenticate — whereas 403 invites a
 * retry that will never succeed.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && method_exists($user, 'isActive') && ! $user->isActive()) {
            // Best-effort cleanup for tokens that predate the model hook. The
            // request is refused either way — this is not the control.
            $user->currentAccessToken()?->delete();

            return response()->json([
                'error' => 'This account is no longer active.',
            ], 401);
        }

        return $next($request);
    }
}
