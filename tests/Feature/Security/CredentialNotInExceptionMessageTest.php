<?php

namespace Tests\Feature\Security;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Integrations\Services\ConnectionTester;
use App\Support\Http\ConnectionExceptionScrubber;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

/**
 * BUG-006 — a credential passed as a query parameter must never reach an
 * exception message.
 *
 * Unlike BUG-005, this cannot be fixed by moving the key to a header: the Places
 * endpoint in use has no header form. The credential stays in the URI, so the
 * fix scrubs it out of ConnectException messages centrally, via
 * Http::globalMiddleware().
 *
 * Three kinds of assertion here, and the distinction matters:
 *
 *  - MECHANISM  — the scrubber redacts query values, keeping names and path.
 *  - CHAIN      — (string) $e carries no credential either. PHP's __toString()
 *                 walks getPrevious(), so scrubbing only the outer message
 *                 leaves the key in whatever stringifies the exception —
 *                 which is exactly what failed_jobs.exception stores.
 *  - CONSEQUENCE— per Places site, the key reaches no surfaced text, read from
 *                 the sink that site actually writes to. GooglePlacesScraper
 *                 swallows the exception in run(), so asserting on a throw
 *                 would test nothing; the real sinks are lead_scrape_jobs.error
 *                 and the log.
 */
class CredentialNotInExceptionMessageTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'AIzaSyPLACESTESTKEY000000000000000000000';

    private const HOST = 'https://maps.googleapis.com';

    /** A Guzzle ConnectException shaped exactly like a real one. */
    private function guzzleConnectException(string $uri): ConnectException
    {
        return new ConnectException(
            'cURL error 6: Could not resolve host: maps.googleapis.com '
            .'(see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for '.$uri,
            new PsrRequest('GET', $uri),
        );
    }

    // ---------------------------------------------------------------- mechanism

    public static function uris(): array
    {
        return [
            'single credential param' => [
                self::HOST.'/maps/api/place/textsearch/json?key='.self::KEY,
                self::HOST.'/maps/api/place/textsearch/json?key=***',
            ],
            'credential among others' => [
                self::HOST.'/x?query=cafes+in+york&key='.self::KEY,
                self::HOST.'/x?query=***&key=***',
            ],
            'no query at all' => [
                self::HOST.'/maps/api/place/textsearch/json',
                self::HOST.'/maps/api/place/textsearch/json',
            ],
        ];
    }

    #[DataProvider('uris')]
    public function test_it_redacts_query_values_but_keeps_names_and_path(string $uri, string $expected): void
    {
        $this->assertSame($expected, ConnectionExceptionScrubber::redactUri($uri));
    }

    public function test_it_leaves_the_diagnostic_parts_of_the_message_intact(): void
    {
        $scrubbed = ConnectionExceptionScrubber::scrub(
            $this->guzzleConnectException(self::HOST.'/api?key='.self::KEY)->getMessage()
        );

        $this->assertStringNotContainsString(self::KEY, $scrubbed);

        // The parts worth having when debugging must survive: errno, host, path,
        // and the parameter name. A scrub that deletes the query string wholesale
        // would pass the leak assertion while destroying diagnostics.
        $this->assertStringContainsString('cURL error 6', $scrubbed);
        $this->assertStringContainsString('maps.googleapis.com', $scrubbed);
        $this->assertStringContainsString('/api', $scrubbed);
        $this->assertStringContainsString('key=***', $scrubbed);
    }

    // -------------------------------------------------------------------- chain

    /**
     * THE assertion that catches a scrub which only fixed the outer message.
     *
     * `(string) $e` includes every previous exception's message. Attaching the
     * unscrubbed original as `previous` would put the credential straight back
     * into what failed_jobs.exception stores.
     */
    public function test_the_credential_does_not_survive_via_the_exception_chain(): void
    {
        $uri = self::HOST.'/maps/api/place/textsearch/json?key='.self::KEY;

        $captured = null;

        Http::fake(fn () => throw $this->guzzleConnectException($uri));

        try {
            Http::get($uri);
        } catch (Throwable $e) {
            $captured = $e;
        }

        $this->assertNotNull($captured, 'Expected the connection failure to surface.');
        $this->assertStringNotContainsString(self::KEY, $captured->getMessage());
        $this->assertStringNotContainsString(
            self::KEY,
            (string) $captured,
            'The credential survives in (string) $e — the exception chain still carries it. '
            .'This is what failed_jobs.exception stores.'
        );
    }

    // -------------------------------------------------------------- consequence

    /**
     * ConnectionTester::test() catches \Throwable and returns the message in its
     * result array, which it also persists to integration_configs
     * .last_test_message. Read the returned message, not a thrown exception.
     */
    public function test_the_connection_tester_does_not_surface_the_places_key(): void
    {
        Http::fake(fn ($request) => throw $this->guzzleConnectException($request->url()));

        $result = (new ConnectionTester)->test(new IntegrationConfig([
            'provider' => 'google_places',
            'credentials' => ['api_key' => self::KEY],
        ]));

        $this->assertIsArray($result);
        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString(
            self::KEY,
            (string) ($result['message'] ?? ''),
            'The Places key reached integration_configs.last_test_message, which the admin UI renders.'
        );
    }

    /**
     * OWED, deliberately skipped — see BUG-007.
     *
     * `GooglePlacesScraper` is the other consequence test this class should
     * carry: `run()` catches \Throwable itself, so nothing is thrown to assert
     * on, and the credential would land in `lead_scrape_jobs.error` — a
     * per-workspace column — plus the log.
     *
     * It cannot be written honestly yet. `run()` dies on its FIRST statement:
     * line 29 calls `$this->credentials->generic('google_places')`, which does
     * not exist on CredentialResolver (the method is `googlePlaces()`), and
     * there is no `__call`. Execution never reaches the HTTP call.
     *
     * So the assertion "the key is not in lead_scrape_jobs.error" would pass —
     * but because no request was ever made, not because anything was scrubbed.
     * A test that passes for the wrong reason is worse than no test: it reads as
     * proof of a protection that was never exercised. This codebase has already
     * shipped several of those (see docs/test-suite-baseline.md, "Zero is a
     * floor, not assurance").
     *
     * Unskip this once BUG-007 is fixed, and stash-check it then — with the
     * scraper working, disabling the middleware must make it fail.
     */
    public function test_the_places_scraper_does_not_persist_the_key_to_the_job_error_column(): void
    {
        $this->markTestSkipped(
            'Owed until BUG-007 is fixed: GooglePlacesScraper::run() dies at line 29 on an '
            .'undefined CredentialResolver::generic(), so no HTTP request is ever made and '
            .'this assertion would pass vacuously.'
        );
    }
}
