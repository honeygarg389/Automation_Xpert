<?php

namespace Tests\Unit\Retry;

use App\Support\Retry\HttpRetry;
use App\Support\Retry\Jitter;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cover for outbound-HTTP retry timing and the retryable/permanent split.
 *
 * Extends PHPUnit's TestCase directly — no container, no database. Building a
 * RequestException only needs a PSR-7 response.
 */
class HttpRetryTest extends TestCase
{
    private const ITERATIONS = 200;

    /** @param array<string, string> $headers */
    private function exception(int $status, array $headers = []): RequestException
    {
        return new RequestException(new Response(new Psr7Response($status, $headers)));
    }

    // ── which failures are worth retrying ───────────────────────────────────

    /** @return array<string, array{0: int}> */
    public static function permanentStatuses(): array
    {
        return [
            '400 bad request' => [400],
            '401 unauthorized' => [401],
            '403 forbidden' => [403],
            '404 not found' => [404],
            '422 unprocessable' => [422],
        ];
    }

    /**
     * A 401 from a bad API key fails identically on every attempt. Retrying it
     * is pure waste — three times the latency for the same error.
     */
    #[DataProvider('permanentStatuses')]
    public function test_permanent_client_errors_are_not_retried(int $status): void
    {
        $this->assertFalse(HttpRetry::shouldRetry($this->exception($status)));
    }

    /** @return array<string, array{0: int}> */
    public static function retryableStatuses(): array
    {
        return [
            '408 request timeout' => [408],
            '429 too many requests' => [429],
            '500 server error' => [500],
            '502 bad gateway' => [502],
            '503 unavailable' => [503],
            '504 gateway timeout' => [504],
        ];
    }

    #[DataProvider('retryableStatuses')]
    public function test_transient_and_server_errors_are_retried(int $status): void
    {
        $this->assertTrue(HttpRetry::shouldRetry($this->exception($status)));
    }

    public function test_connection_failures_are_retried(): void
    {
        $this->assertTrue(HttpRetry::shouldRetry(new ConnectionException('cURL error 28: timed out')));
    }

    /**
     * Anything that is not a recognised HTTP failure is treated as permanent.
     * Retrying an unknown throwable risks replaying a non-idempotent call.
     */
    public function test_unknown_failures_are_not_retried(): void
    {
        $this->assertFalse(HttpRetry::shouldRetry(null));
        $this->assertFalse(HttpRetry::shouldRetry(new \RuntimeException('parse error')));
    }

    // ── delay growth ────────────────────────────────────────────────────────

    /** @return array<string, array{0: int, 1: int, 2: int}> */
    public static function attemptWindows(): array
    {
        // attempt => [lower bound ms, upper bound ms] for a 500ms base:
        // 500, 1000, 2000, 4000 … each +30% jitter.
        return [
            'attempt 1' => [1, 500, 650],
            'attempt 2' => [2, 1000, 1300],
            'attempt 3' => [3, 2000, 2600],
            'attempt 4' => [4, 4000, 5200],
        ];
    }

    #[DataProvider('attemptWindows')]
    public function test_the_delay_grows_exponentially_within_its_jitter_window(int $attempt, int $low, int $high): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $delay = HttpRetry::sleepMs($attempt);

            $this->assertGreaterThanOrEqual($low, $delay);
            $this->assertLessThanOrEqual($high, $delay);
        }
    }

    /**
     * The fault being fixed: `Http::retry(2, 500)` waited a constant 500ms on
     * every attempt, so both attempts landed inside the same failure window.
     */
    public function test_later_attempts_wait_strictly_longer_than_earlier_ones(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $this->assertGreaterThan(HttpRetry::sleepMs(1), HttpRetry::sleepMs(2));
            $this->assertGreaterThan(HttpRetry::sleepMs(2), HttpRetry::sleepMs(3));
        }
    }

    public function test_growth_is_bounded_by_the_max_sleep(): void
    {
        foreach ([8, 12, 20, 64] as $attempt) {
            $this->assertSame(HttpRetry::MAX_SLEEP_MS, HttpRetry::sleepMs($attempt));
        }
    }

    public function test_repeated_calls_produce_different_delays(): void
    {
        $seen = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $seen[HttpRetry::sleepMs(2)] = true;
        }

        $this->assertGreaterThan(1, count($seen), 'sleepMs returned an identical delay every time');
    }

    public function test_delays_are_integers(): void
    {
        $this->assertIsInt(HttpRetry::sleepMs(1));
    }

    // ── Retry-After ─────────────────────────────────────────────────────────

    /**
     * A 429 carrying Retry-After is the server stating when it will serve us.
     * That wins over the computed schedule — but still gets a small spread so
     * every client handed the same header does not return in the same instant.
     */
    public function test_a_numeric_retry_after_header_wins_over_the_computed_delay(): void
    {
        $exception = $this->exception(429, ['Retry-After' => '5']);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $delay = HttpRetry::sleepMs(1, $exception);

            $this->assertGreaterThanOrEqual(5000, $delay);
            $this->assertLessThanOrEqual((int) (5000 * (1 + HttpRetry::RETRY_AFTER_RATIO)), $delay);
        }
    }

    public function test_a_retry_after_header_is_jittered_rather_than_returned_verbatim(): void
    {
        $exception = $this->exception(429, ['Retry-After' => '30']);
        $seen = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $seen[HttpRetry::sleepMs(1, $exception)] = true;
        }

        $this->assertGreaterThan(1, count($seen), 'Retry-After was returned without jitter');
    }

    public function test_an_http_date_retry_after_is_honoured(): void
    {
        $exception = $this->exception(503, ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 20)]);

        $delay = HttpRetry::sleepMs(1, $exception);

        // Allow a second of slack for clock movement between building the header
        // and reading it.
        $this->assertGreaterThanOrEqual(19_000, $delay);
        $this->assertLessThanOrEqual((int) (20_000 * (1 + HttpRetry::RETRY_AFTER_RATIO)), $delay);
    }

    /**
     * A Retry-After already in the past must not short-circuit the backoff to
     * zero — it falls back to the computed exponential delay.
     */
    public function test_a_past_retry_after_falls_back_to_the_computed_delay(): void
    {
        $exception = $this->exception(503, ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() - 120)]);

        $delay = HttpRetry::sleepMs(2, $exception);

        $this->assertGreaterThanOrEqual(1000, $delay);
        $this->assertLessThanOrEqual(1300, $delay);
    }

    public function test_an_unparseable_retry_after_falls_back_to_the_computed_delay(): void
    {
        $exception = $this->exception(503, ['Retry-After' => 'soon-ish']);

        $delay = HttpRetry::sleepMs(1, $exception);

        $this->assertGreaterThanOrEqual(500, $delay);
        $this->assertLessThanOrEqual(650, $delay);
    }

    public function test_a_retryable_response_without_retry_after_uses_the_schedule(): void
    {
        $delay = HttpRetry::sleepMs(3, $this->exception(500));

        $this->assertGreaterThanOrEqual(2000, $delay);
        $this->assertLessThanOrEqual(2600, $delay);
    }

    public function test_it_reuses_the_shared_jitter_ratio(): void
    {
        $this->assertSame(0.3, Jitter::DEFAULT_RATIO);
        $this->assertSame(0.1, HttpRetry::RETRY_AFTER_RATIO);
    }
}
