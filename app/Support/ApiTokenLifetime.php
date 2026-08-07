<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * SEC-006. How long an issued Sanctum token is allowed to live.
 *
 * `config('sanctum.expiration')` is deliberately left null. It is NOT the right
 * control here, for two reasons that are easy to get wrong:
 *
 *  1. It is measured from `created_at`, never `last_used_at` (Guard.php:128).
 *     It is an absolute lifetime, not an idle timeout — an active user is
 *     logged out on the same schedule as an abandoned token.
 *  2. It is a hard cap on EVERY token. A customer who deliberately creates a
 *     one-year integration token would find it dead at the global limit, with
 *     nothing in the UI explaining why. "Integrations broke for no reason" is a
 *     support problem you meet months later.
 *
 * ⚠️ Laravel's own comment in config/sanctum.php says the global value "will
 * override any values set in the token's expires_at attribute". That is WRONG.
 * Guard.php ANDs the two checks, so the STRICTER of the two wins — a global cap
 * cannot extend a short per-token expiry, and a long per-token expiry cannot
 * escape a global cap. See docs/found-bugs.md.
 *
 * So expiry is set per token, at the point of issue, where the difference
 * between a phone and a server integration is actually known.
 */
final class ApiTokenLifetime
{
    /**
     * A mobile login token.
     *
     * 30 days is not daily re-authentication: it survives a holiday, matches
     * what a consumer app does, and still bounds a stolen phone. Combined with
     * revocation on password change, a compromised device has a real ceiling.
     */
    public const MOBILE_DAYS = 30;

    /** The default when a user creates an API token and leaves expiry blank. */
    public const API_DEFAULT_DAYS = 90;

    /**
     * The longest expiry a user may choose.
     *
     * Long-lived integration tokens are legitimate; unbounded ones are not. A
     * year means a token is re-issued at a human cadence rather than never.
     */
    public const API_MAX_DAYS = 365;

    public static function forMobile(): Carbon
    {
        return now()->addDays(self::MOBILE_DAYS);
    }

    /**
     * Resolve the expiry for a user-created API token.
     *
     * Blank gets the default. An explicit choice is HONOURED — that is the
     * point of the field — but capped, so "no expiry" cannot be reintroduced by
     * choosing the year 3000.
     *
     * Accepts any DateTimeInterface rather than a Carbon: the callers parse
     * with `Carbon\Carbon`, which is NOT `Illuminate\Support\Carbon`, and a
     * narrower hint here is a TypeError at the one moment a user picks a date.
     */
    public static function forApiToken(?\DateTimeInterface $requested): Carbon
    {
        if ($requested === null) {
            return now()->addDays(self::API_DEFAULT_DAYS);
        }

        $requested = Carbon::instance(\DateTimeImmutable::createFromInterface($requested));
        $max = now()->addDays(self::API_MAX_DAYS);

        return $requested->greaterThan($max) ? $max : $requested;
    }
}
