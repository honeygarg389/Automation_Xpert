<?php

namespace App\Support\Retry;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Retry timing for outbound HTTP calls.
 *
 * Replaces `Http::retry(2, 500)`, which had two timing faults:
 *
 *  - The 500ms delay is constant, so every attempt lands inside the same failure
 *    window and every caller retries in lockstep. See {@see Jitter}.
 *  - `retry()` with no `when` retries ANY failure, including permanent 4xx.
 *    Retrying a 401 from a bad API key cannot ever succeed — it just triples the
 *    latency before surfacing the same error.
 */
final class HttpRetry
{
    /** Transient client statuses: request timeout and rate limit. */
    private const RETRYABLE_STATUSES = [408, 429];

    /** Ceiling on a single computed delay (ms), so exponential growth is bounded. */
    public const MAX_SLEEP_MS = 30_000;

    /** Retry-After is authoritative, so it gets only a small spread. */
    public const RETRY_AFTER_RATIO = 0.1;

    /**
     * Milliseconds to wait before `$attempt`'s retry.
     *
     * Grows exponentially (base, base*2, base*4, …) with jitter on top, bounded
     * by MAX_SLEEP_MS. If the response carried a Retry-After header that value
     * wins outright — the server knows better than the schedule — with a small
     * jitter so clients that got the same header do not all return at once.
     *
     * Intended as the `sleepMilliseconds` closure argument to `Http::retry()`,
     * which passes the attempt number and the triggering exception.
     */
    public static function sleepMs(int $attempt, ?Throwable $exception = null, int $baseMs = 500): int
    {
        $retryAfterMs = self::retryAfterMs($exception);

        if ($retryAfterMs !== null) {
            // Deliberately not capped by MAX_SLEEP_MS: clamping it would return
            // before the server said it would serve us, which is the one thing
            // honouring the header is meant to prevent.
            return Jitter::jitter($retryAfterMs, self::RETRY_AFTER_RATIO);
        }

        $base = (int) min($baseMs * (2 ** max(0, $attempt - 1)), self::MAX_SLEEP_MS);

        return Jitter::jitter($base, Jitter::DEFAULT_RATIO, self::MAX_SLEEP_MS);
    }

    /**
     * Whether a failure is worth another attempt.
     *
     * Retryable: connection failures (nothing was served), 408, 429, and any
     * 5xx. Everything else — notably 400/401/403/404/422 — is a permanent
     * client error that will fail identically on every attempt.
     *
     * Intended as the `when` closure argument to `Http::retry()`.
     */
    public static function shouldRetry(?Throwable $exception = null): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        $status = self::statusOf($exception);

        if ($status === null) {
            return false;
        }

        return $status >= 500 || in_array($status, self::RETRYABLE_STATUSES, true);
    }

    private static function statusOf(?Throwable $exception): ?int
    {
        return $exception instanceof RequestException
            ? $exception->response->status()
            : null;
    }

    /**
     * Retry-After as milliseconds, or null when absent/unparseable.
     * The header is either delta-seconds or an HTTP-date.
     */
    private static function retryAfterMs(?Throwable $exception): ?int
    {
        if (! $exception instanceof RequestException) {
            return null;
        }

        $header = trim($exception->response->header('Retry-After'));

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header * 1000;
        }

        $timestamp = strtotime($header);

        if ($timestamp === false) {
            return null;
        }

        $delta = $timestamp - time();

        return $delta > 0 ? $delta * 1000 : null;
    }
}
