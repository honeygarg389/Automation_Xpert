<?php

namespace Tests\Feature\Security;

use App\Modules\AI\Services\Llm\GeminiProvider;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Integrations\Services\ConnectionTester;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

/**
 * BUG-005 — the Gemini API key must never appear in a request URL.
 *
 * Gemini used to authenticate with `?key={$apiKey}`. On a CONNECTION failure
 * (DNS, TLS, timeout — not an HTTP error status) Guzzle appends the URI verbatim
 * to the exception message:
 *
 *     // vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php:271
 *     $message .= sprintf(' for %s', $redactedUriString);
 *
 * `Utils::redactUserInfo()` redacts only `user:pass@host`; it does not touch the
 * query string. That message then reaches four places: the errors log channel
 * (bootstrap/app.php:146 logs EVERY reportable exception message), the
 * failed_jobs.exception column, the admin queue UI, and a JSON 422 response body
 * via AiChatbotController's catch(\Throwable).
 *
 * `RequestException` was never a vector — its message is built from the response
 * status and body only. Only ConnectionException leaked, which is exactly the
 * condition retries make more likely to be hit repeatedly.
 *
 * These tests assert two different things on purpose:
 *
 *  - the MECHANISM: the key is sent as the x-goog-api-key header and appears
 *    nowhere in the URL;
 *  - the CONSEQUENCE: a real ConnectionException message does not contain the
 *    key. That is what BUG-005 actually was, and it holds regardless of how the
 *    URL is built — it would still fail if someone reintroduced the key under a
 *    different parameter name.
 */
class GeminiApiKeyNotInUrlTest extends TestCase
{
    /**
     * A key with no regex-special characters and no substring that occurs
     * naturally in a Gemini URL, so a match is unambiguous.
     */
    private const KEY = 'AIzaSyTESTKEY0000000000000000000000000000';

    /** The three sites that authenticated to Gemini with a URL query parameter. */
    public static function geminiCallSites(): array
    {
        return [
            'GeminiProvider::chat' => [fn () => (new GeminiProvider(self::KEY))
                ->chat([['role' => 'user', 'content' => 'hi']])],

            'GeminiProvider::embed' => [fn () => (new GeminiProvider(self::KEY))
                ->embed(['hello'])],

            // The `llm_` prefix is required: ConnectionTester::test() dispatches on
            // str_starts_with($provider, 'llm_'), and a bare 'gemini' falls through
            // to the default branch without sending anything.
            'ConnectionTester::gemini' => [fn () => (new ConnectionTester)->test(
                new IntegrationConfig([
                    'provider' => 'llm_gemini',
                    'credentials' => ['api_key' => self::KEY],
                ])
            )],
        ];
    }

    /** Run a call site and swallow whatever it throws. */
    private function attempt(callable $callSite): void
    {
        try {
            $callSite();
        } catch (Throwable) {
            // Several scenarios below end in a failed or refused response.
        }
    }

    /**
     * The mechanism, asserted against the FULL url() rather than just "no key=
     * param". Checking for the literal key string means a partial fix — moving it
     * to `?api_key=` or `?apikey=`, say — still fails this test.
     *
     * Http::fake() records the complete URI including the query string; that was
     * verified against the unfixed code before these tests were written, so the
     * assertion is not vacuous.
     */
    #[DataProvider('geminiCallSites')]
    public function test_the_api_key_appears_nowhere_in_the_request_url(callable $callSite): void
    {
        Http::fake(['*' => Http::response(['candidates' => [], 'models' => []], 200)]);

        $this->attempt($callSite);

        Http::assertSent(function ($request) {
            $this->assertStringNotContainsString(
                self::KEY,
                $request->url(),
                'The Gemini API key is present in the request URL. Guzzle appends the URI '
                .'to ConnectionException messages, so this leaks the key into logs, '
                .'failed_jobs, the admin queue UI and API responses (BUG-005).'
            );

            return true;
        });
    }

    /** The key must still be sent — as a header. A request that authenticates with
     *  nothing at all would also satisfy the assertion above. */
    #[DataProvider('geminiCallSites')]
    public function test_the_api_key_is_sent_as_the_x_goog_api_key_header(callable $callSite): void
    {
        Http::fake(['*' => Http::response(['candidates' => [], 'models' => []], 200)]);

        $this->attempt($callSite);

        Http::assertSent(fn ($request) => $request->hasHeader('x-goog-api-key', self::KEY));
    }

    /**
     * THE regression test for BUG-005 itself.
     *
     * This asserts the consequence rather than the mechanism: whatever the URL is
     * built from, a connection failure must not produce a message containing the
     * key. If the fix were reverted this fails even if the URL assertions above
     * were somehow satisfied.
     */
    #[DataProvider('geminiCallSites')]
    public function test_a_connection_failure_message_does_not_contain_the_api_key(callable $callSite): void
    {
        // The message MUST be built from the request the site actually sent.
        // An earlier draft reconstructed it from Http::recorded(), which a throwing
        // fake never populates — so it fell back to a URI with no key and the test
        // passed even with the fix reverted. It was proving nothing.
        Http::fake(function ($request) {
            // Mirrors what Guzzle produces for a real connection failure:
            // CurlFactory:271 appends the full request URI to the message.
            throw new ConnectionException(
                'cURL error 6: Could not resolve host (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) '
                .'for '.$request->url()
            );
        });

        // The failure surfaces in one of two shapes. GeminiProvider lets the
        // exception propagate. ConnectionTester::test() catches \Throwable and
        // returns the message in the result array — which it then PERSISTS to
        // integration_configs.last_test_message and renders in the admin UI, so
        // that string is every bit as exposed as a logged exception.
        $surfaced = '';

        try {
            $result = $callSite();

            if (is_array($result) && isset($result['message'])) {
                $surfaced = (string) $result['message'];
            }
        } catch (Throwable $e) {
            $surfaced = $e->getMessage();
        }

        $this->assertNotSame('', $surfaced, 'Expected the connection failure to surface somewhere.');
        $this->assertStringNotContainsString(
            self::KEY,
            $surfaced,
            'A connection failure surfaced the Gemini API key. Such messages are logged by '
            .'bootstrap/app.php:146, stored in failed_jobs.exception and in '
            .'integration_configs.last_test_message, rendered in the admin UI, and returned '
            .'to the browser by AiChatbotController.'
        );
    }
}
