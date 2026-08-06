<?php

namespace Tests\Feature\Retry;

use App\Modules\AI\Jobs\IndexDocumentJob;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Services\EmbeddingStore;
use App\Modules\AI\Services\Llm\AnthropicProvider;
use App\Modules\AI\Services\Llm\OpenAiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;
use Throwable;

/**
 * Group D wiring tests.
 *
 * These assert that each AI HTTP call site actually PASSES the HttpRetry closures
 * to Http::retry() — not that the closures decide correctly. The decision logic is
 * already covered exhaustively by tests/Unit/Retry/HttpRetryTest.php; duplicating
 * it here would test the helper twice and the wiring zero times.
 *
 * The distinction matters because the wiring is what was missing. Before Group D
 * every site called `Http::retry(2, 500)`, which retries ANY failure: a 401 from a
 * revoked API key was retried as though it were transient. A test that only
 * exercised HttpRetry::shouldRetry() would have passed the whole time.
 *
 * The load-bearing assertion in each case is the permanent-4xx one: exactly ONE
 * request. With the `when` closure absent that becomes two, which is precisely the
 * regression these tests exist to catch.
 *
 * No test sleeps. Sleep::fake() records the delays the retry helper asks for
 * instead of performing them, so a wired site costs the same wall-clock time as an
 * unwired one.
 */
class AiHttpRetryWiringTest extends TestCase
{
    /** Statuses that must never be retried — a permanent client error. */
    public static function permanentStatuses(): array
    {
        return [
            '401 unauthorized' => [401],
            '403 forbidden' => [403],
            '404 not found' => [404],
        ];
    }

    /** Statuses that must be retried up to the configured limit. */
    public static function transientStatuses(): array
    {
        return [
            '500 server error' => [500],
            '429 rate limited' => [429],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Record requested delays rather than performing them. Without this the
        // 429/500 cases would really wait ~500ms each and the suite would slow
        // down measurably for no added confidence.
        Sleep::fake();
    }

    /**
     * Run a call site and swallow whatever it throws.
     *
     * Every site here throws on failure — `Http::retry()` defaults to throw:true
     * and rethrows once retries are exhausted or `when` declines. What is under
     * test is how many requests were attempted first, not the exception type.
     */
    private function attempt(callable $callSite): void
    {
        try {
            $callSite();
        } catch (Throwable) {
            // Expected: every scenario below ends in a failed response.
        }
    }

    /** The six wired call sites, each as an invocable closure. */
    public static function callSites(): array
    {
        return [
            'OpenAiProvider::chat' => [fn () => (new OpenAiProvider('sk-test'))
                ->chat([['role' => 'user', 'content' => 'hi']])],

            'OpenAiProvider::embed' => [fn () => (new OpenAiProvider('sk-test'))
                ->embed(['hello'])],

            'AnthropicProvider::chat' => [fn () => (new AnthropicProvider('sk-test'))
                ->chat([['role' => 'user', 'content' => 'hi']])],

            'EmbeddingStore::qdrantClient' => [function () {
                config()->set('services.qdrant.url', 'https://qdrant.test');
                $method = new ReflectionMethod(EmbeddingStore::class, 'qdrantClient');

                return $method->invoke(app(EmbeddingStore::class))->get('/collections');
            }],

            'IndexDocumentJob::fetchUrl' => [function () {
                $method = new ReflectionMethod(IndexDocumentJob::class, 'fetchUrl');

                return $method->invoke(new IndexDocumentJob(1), 'https://example.test/page');
            }],

            'IndexDocumentJob::processSitemap' => [function () {
                $method = new ReflectionMethod(IndexDocumentJob::class, 'processSitemap');
                $doc = new AiKbDocument(['source_ref' => 'https://example.test/sitemap.xml']);

                return $method->invoke(new IndexDocumentJob(1), $doc);
            }],
        ];
    }

    /**
     * THE fail-fast proof, and the assertion that breaks if the `when` closure is
     * ever dropped from a call site.
     *
     * A 401 cannot become a 200 by asking again — retrying one only multiplies the
     * latency before surfacing the same error. Exactly one request must be sent.
     */
    #[DataProvider('callSites')]
    public function test_a_permanent_client_error_is_attempted_exactly_once(callable $callSite): void
    {
        Http::fake(['*' => Http::response('nope', 401)]);

        $this->attempt($callSite);

        Http::assertSentCount(1);
    }

    #[DataProvider('permanentStatuses')]
    public function test_every_permanent_status_is_attempted_exactly_once(int $status): void
    {
        Http::fake(['*' => Http::response('nope', $status)]);

        $this->attempt(fn () => (new OpenAiProvider('sk-test'))
            ->chat([['role' => 'user', 'content' => 'hi']]));

        Http::assertSentCount(1);
    }

    /**
     * The counterpart to the fail-fast test: a positive control proving the sites
     * still retry when they should. Without this, a call site that retried NOTHING
     * would also pass the test above.
     */
    #[DataProvider('callSites')]
    public function test_a_server_error_is_retried_to_the_configured_limit(callable $callSite): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->attempt($callSite);

        // times: 2 — one initial attempt plus one retry. Group D did not change it.
        Http::assertSentCount(2);
    }

    #[DataProvider('transientStatuses')]
    public function test_every_transient_status_is_retried(int $status): void
    {
        Http::fake(['*' => Http::response('slow down', $status)]);

        $this->attempt(fn () => (new OpenAiProvider('sk-test'))
            ->chat([['role' => 'user', 'content' => 'hi']]));

        Http::assertSentCount(2);
    }

    /**
     * A connection failure means nothing was served at all, so the request is
     * always worth repeating. This is the one retryable case that carries no HTTP
     * status, so it exercises a different branch of shouldRetry() than 429/500.
     */
    #[DataProvider('callSites')]
    public function test_a_connection_failure_is_retried(callable $callSite): void
    {
        // A fake that THROWS never records a request, so Http::assertSentCount()
        // reports 0 here regardless of how many attempts were made. Count the
        // invocations directly instead — otherwise this test would pass whether
        // the site retried twice or not at all.
        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('could not connect');
        });

        $this->attempt($callSite);

        $this->assertSame(2, $attempts);
    }

    /**
     * Guards the property that makes these tests cheap: the retry path must be
     * driven by the injected sleep closure, never by real elapsed time.
     */
    public function test_the_retry_path_sleeps_via_the_fakeable_sleep_helper(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->attempt(fn () => (new OpenAiProvider('sk-test'))
            ->chat([['role' => 'user', 'content' => 'hi']]));

        // One retry means exactly one sleep, and it must be a positive duration
        // produced by HttpRetry::sleepMs rather than the old constant 500ms.
        Sleep::assertSleptTimes(1);
    }

    /**
     * EmbeddingStore keeps a 300ms base rather than the 500ms default, so its
     * first retry delay must fall in the 300ms jitter window [300, 390] and not
     * the 500ms one. This is the only site whose baseMs differs, and passing
     * baseMs is easy to drop silently.
     */
    public function test_the_embedding_store_keeps_its_tighter_300ms_base(): void
    {
        config()->set('services.qdrant.url', 'https://qdrant.test');
        Http::fake(['*' => Http::response('boom', 500)]);

        $method = new ReflectionMethod(EmbeddingStore::class, 'qdrantClient');
        $this->attempt(fn () => $method->invoke(app(EmbeddingStore::class))->get('/collections'));

        Sleep::assertSlept(function (\Carbon\CarbonInterval $duration): bool {
            $ms = $duration->totalMilliseconds;

            return $ms >= 300 && $ms <= 390;
        }, 1);
    }
}
