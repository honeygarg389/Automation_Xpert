<?php

namespace App\Support\Http;

use GuzzleHttp\Exception\ConnectException;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * Keeps credentials out of connection-failure messages.
 *
 * Some APIs authenticate with a query parameter rather than a header — Google
 * Places (`?key=`), Meta (`?access_token=`), Nexmo (`?api_key=&api_secret=`) and
 * others. Guzzle appends the full request URI to the message of every
 * ConnectException it raises:
 *
 *     // vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php:271
 *     $message .= sprintf(' for %s', $redactedUriString);
 *
 * and `Utils::redactUserInfo()` redacts only `user:pass@host` — it never touches
 * the query string. The credential is then inside an exception message, and this
 * codebase writes exception messages to the errors log, to `failed_jobs`, to
 * `lead_scrape_jobs.error`, to `integration_configs.last_test_message`, and
 * straight back to HTTP clients. See BUG-005 and BUG-006 in docs/found-bugs.md.
 *
 * Fixing it per call site fixes only the sites that exist today. This scrubs
 * centrally instead, so a credential passed in a query string cannot reach a
 * message regardless of which provider, parameter name, or call site is involved.
 *
 * Redaction keeps parameter NAMES and the path, and replaces only the VALUES:
 *
 *     https://host/api?query=cafes&key=AIza…  ->  https://host/api?query=***&key=***
 *
 * Names and path are the parts worth having when debugging; values are the part
 * that can be secret. Redacting every value rather than a denylist of known
 * credential names means a parameter nobody anticipated is still covered.
 */
final class ConnectionExceptionScrubber
{
    /** Replacement for every query-parameter value. */
    public const REDACTED = '***';

    /**
     * Guzzle middleware. Register once via `Http::globalMiddleware()`.
     */
    public static function middleware(): callable
    {
        return static function (callable $handler): callable {
            return static function (RequestInterface $request, array $options) use ($handler) {
                try {
                    $promise = $handler($request, $options);
                } catch (Throwable $e) {
                    // A handler that throws synchronously rather than returning a
                    // rejected promise — notably Http::fake() stubs.
                    throw self::rewrite($e);
                }

                return $promise->otherwise(static fn (mixed $reason) => throw self::rewrite($reason));
            };
        };
    }

    /**
     * Replace a ConnectException with an equivalent carrying a scrubbed message.
     *
     * The original is deliberately NOT attached as `previous`. PHP's
     * `Exception::__toString()` walks the whole chain, so an unscrubbed previous
     * would put the credential straight back into `(string) $e` — which is what
     * gets written to `failed_jobs.exception`. Scrubbing only the outer message
     * looks correct and closes nothing.
     *
     * The cURL errno, the host and the path all survive in the scrubbed message,
     * so the diagnostic value of the original is kept.
     */
    private static function rewrite(mixed $reason): mixed
    {
        if (! $reason instanceof ConnectException) {
            return $reason instanceof Throwable ? $reason : new \RuntimeException('Unknown HTTP failure');
        }

        return new ConnectException(
            self::scrub($reason->getMessage()),
            $reason->getRequest(),
            null,
            self::scrubContext($reason->getHandlerContext()),
        );
    }

    /**
     * Redact query-parameter values in every URL appearing in a string.
     *
     * Matching is anchored on `http(s)://…` so ordinary prose containing an `=`
     * is left alone; only URLs are rewritten.
     */
    public static function scrub(string $message): string
    {
        return (string) preg_replace_callback(
            '#https?://\S+#i',
            static fn (array $m): string => self::redactUri($m[0]),
            $message,
        );
    }

    /**
     * Redact the values of a single URI's query string, preserving scheme, host,
     * path, parameter names and order. A URI with no query is returned unchanged.
     */
    public static function redactUri(string $uri): string
    {
        $query = parse_url($uri, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return $uri;
        }

        $redacted = (string) preg_replace('/([^&=]+)=([^&]*)/', '$1='.self::REDACTED, $query);

        // Replace only the query portion; the fragment and path are untouched.
        $position = strpos($uri, '?'.$query);

        return $position === false
            ? $uri
            : substr_replace($uri, '?'.$redacted, $position, strlen($query) + 1);
    }

    /**
     * Guzzle's handler context carries curl diagnostics including `url`, so it is
     * scrubbed on the same terms as the message.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function scrubContext(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $context[$key] = self::scrub($value);
            }
        }

        return $context;
    }
}
