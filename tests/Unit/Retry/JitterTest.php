<?php

namespace Tests\Unit\Retry;

use App\Support\Retry\Jitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cover for the shared retry-jitter helper.
 *
 * Extends PHPUnit's TestCase directly rather than Tests\TestCase, matching
 * tests/Unit/Rules/PublicHttpUrlTest.php: this is a pure function, so the test
 * needs no application container and no database.
 *
 * Every assertion here is repeated across many iterations. A single call proves
 * nothing about a randomized function — it can pass on a lucky draw — so the
 * invariants are checked over the whole distribution.
 */
class JitterTest extends TestCase
{
    /** Enough draws that a violated invariant is near-certain to show up. */
    private const ITERATIONS = 300;

    /** @return array<string, array{0: list<int>, 1: int|null}> */
    public static function schedules(): array
    {
        return [
            'campaign send (A)' => [[60, 180, 600], null],
            'house 3-try (B)' => [[30, 120, 300], null],
            'house 2-try (B)' => [[30, 120], null],
            'inbound 5-try (C)' => [[30, 60, 120, 240, 300], 300],
            'ecommerce 3-try (C)' => [[30, 120, 300], 300],
            'single delay' => [[45], null],
        ];
    }

    #[DataProvider('schedules')]
    public function test_it_preserves_the_number_of_delays(array $bases, ?int $cap): void
    {
        $this->assertCount(count($bases), Jitter::jittered($bases, Jitter::DEFAULT_RATIO, $cap));
    }

    /**
     * The core guarantee: additive and upward only. A jittered delay is never
     * EARLIER than the backoff intended, and never more than ratio later.
     */
    #[DataProvider('schedules')]
    public function test_every_delay_lands_within_its_base_window(array $bases, ?int $cap): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $delays = Jitter::jittered($bases, Jitter::DEFAULT_RATIO, $cap);

            foreach ($delays as $position => $delay) {
                $base = $bases[$position];
                $upper = (int) floor($base * (1 + Jitter::DEFAULT_RATIO));

                if ($cap !== null) {
                    $upper = min($upper, $cap);
                }

                $this->assertGreaterThanOrEqual(
                    $base,
                    $delay,
                    "position {$position} fired sooner than its base delay of {$base}s",
                );
                $this->assertLessThanOrEqual(
                    $upper,
                    $delay,
                    "position {$position} exceeded its {$upper}s ceiling",
                );
            }
        }
    }

    #[DataProvider('schedules')]
    public function test_delays_are_non_decreasing(array $bases, ?int $cap): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $delays = Jitter::jittered($bases, Jitter::DEFAULT_RATIO, $cap);

            // Compared against a sorted copy rather than looped pairwise, so the
            // assertion still runs for a single-delay schedule.
            $ascending = $delays;
            sort($ascending);

            $this->assertSame(
                $ascending,
                $delays,
                'schedule went backwards: '.implode(', ', $delays),
            );
        }
    }

    /** @return array<string, array{0: list<int>, 1: int}> */
    public static function cappedSchedules(): array
    {
        return [
            'inbound 5-try (C)' => [[30, 60, 120, 240, 300], 300],
            'ecommerce 3-try (C)' => [[30, 120, 300], 300],
        ];
    }

    /**
     * Group C schedules already grew and capped correctly, so jitter must not
     * lift the tail above the cap those jobs documented.
     */
    #[DataProvider('cappedSchedules')]
    public function test_the_cap_is_never_exceeded(array $bases, int $cap): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            foreach (Jitter::jittered($bases, Jitter::DEFAULT_RATIO, $cap) as $delay) {
                $this->assertLessThanOrEqual($cap, $delay);
            }
        }
    }

    /**
     * The regression this helper's non-decreasing clamp exists for.
     *
     * [240, 300] are spaced by only 1.25x, which is less than the 1.3x jitter
     * ratio. Jittering each independently lets 240 draw 312 while 300 draws 300,
     * producing a schedule that goes backwards. This is exactly the tail of the
     * live [30, 60, 120, 240, 300] inbound-message schedule.
     */
    public function test_a_tightly_spaced_pair_still_never_decreases(): void
    {
        for ($i = 0; $i < 2000; $i++) {
            [$first, $second] = Jitter::jittered([240, 300], Jitter::DEFAULT_RATIO, 300);

            $this->assertGreaterThanOrEqual($first, $second, "240 -> {$first}, 300 -> {$second}");
        }
    }

    /**
     * Proves the randomization is actually live rather than a no-op wrapper —
     * the failure mode where jitter is "added" but every call returns the base
     * schedule unchanged.
     */
    public function test_repeated_calls_produce_different_schedules(): void
    {
        $seen = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $seen[implode(',', Jitter::jittered([30, 120, 300]))] = true;
        }

        // With 3 delays each drawn from a window of 10+ integers, 300 identical
        // draws is far beyond vanishingly unlikely.
        $this->assertGreaterThan(1, count($seen), 'jitter produced an identical schedule every time');
    }

    public function test_a_zero_ratio_is_the_identity(): void
    {
        $this->assertSame([30, 120, 300], Jitter::jittered([30, 120, 300], 0.0));
    }

    public function test_delays_are_integers(): void
    {
        foreach (Jitter::jittered([30, 120, 300]) as $delay) {
            $this->assertIsInt($delay);
        }
    }

    public function test_it_jitters_a_single_delay(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $delay = Jitter::jitter(500);

            $this->assertGreaterThanOrEqual(500, $delay);
            $this->assertLessThanOrEqual(650, $delay);
        }
    }

    public function test_an_empty_schedule_stays_empty(): void
    {
        $this->assertSame([], Jitter::jittered([]));
    }
}
