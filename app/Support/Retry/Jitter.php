<?php

namespace App\Support\Retry;

/**
 * Randomizes retry delays so failed work does not retry in lockstep.
 *
 * Why this exists: a fixed delay makes every job that failed together retry
 * together. When a provider goes down mid-campaign, thousands of sends fail in
 * the same second, wait the same 60s, and hit the recovering provider as one
 * synchronized wave — which knocks it back over and starts the cycle again.
 * Spreading each delay across a small random window breaks that alignment.
 *
 * Jitter here is additive and upward only: a delay lands in
 * [base, base * (1 + ratio)]. A retry therefore never fires SOONER than the
 * backoff intended — only up to `ratio` later.
 */
final class Jitter
{
    /** Default spread: up to +30% on top of each base delay. */
    public const DEFAULT_RATIO = 0.3;

    /**
     * Jitter a whole backoff schedule.
     *
     * The result is the same length as the input and is guaranteed
     * non-decreasing, so exponential growth survives the randomization: without
     * that guarantee a tightly-spaced pair like [240, 300] could jitter to
     * [312, 300] and the schedule would go backwards.
     *
     * @param  list<int>  $baseDelays  intended schedule, ascending
     * @param  float  $ratio  upper spread (0.3 = up to +30%)
     * @param  int|null  $cap  hard ceiling no jittered delay may exceed. Pass a
     *                         schedule's own maximum to preserve an existing cap.
     *                         Must be >= max($baseDelays), otherwise a delay
     *                         would be clamped below its intended base.
     * @return list<int> jittered delays, non-decreasing
     */
    public static function jittered(array $baseDelays, float $ratio = self::DEFAULT_RATIO, ?int $cap = null): array
    {
        $out = [];
        $previous = 0;

        foreach ($baseDelays as $base) {
            $base = (int) $base;

            // Lower bound: never sooner than the intended delay, and never
            // before the delay already returned for the previous attempt.
            $low = max($base, $previous);
            $high = (int) floor($base * (1 + $ratio));

            if ($cap !== null) {
                $low = min($low, $cap);
                $high = min($high, $cap);
            }

            // A cap can pull `high` under `low`; keep the range valid.
            $high = max($high, $low);

            $delay = $low === $high ? $low : random_int($low, $high);

            $out[] = $delay;
            $previous = $delay;
        }

        return $out;
    }

    /**
     * Jitter a single delay. Used by the HTTP client, which computes one delay
     * per attempt rather than a whole schedule up front.
     */
    public static function jitter(int $base, float $ratio = self::DEFAULT_RATIO, ?int $cap = null): int
    {
        return self::jittered([$base], $ratio, $cap)[0];
    }
}
