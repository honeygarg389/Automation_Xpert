<?php

namespace App\Modules\SmartQr\Services;

/**
 * Privacy-safe visitor fingerprinting for unique-scan counting (§10).
 *
 * ─── ⚠️ THE KEY IS FIXED, AND THAT IS NOT AN OVERSIGHT ──────────────────────
 *
 * Rotating the HMAC key does not "improve" anything — it silently invalidates
 * every unique-scan comparison ever made, because the same visitor hashes to a
 * new value afterwards and every repeat scan starts counting as unique. The
 * numbers would drift upward with no error anywhere.
 *
 * So the key comes from `APP_KEY`, which is already the thing whose rotation
 * invalidates encrypted columns across this codebase — one rotation event to
 * reason about rather than two.
 *
 * ─── ⚠️ NO RAW IP IS RETURNED, EVER ─────────────────────────────────────────
 *
 * These methods take the raw values and hand back hashes. Nothing else in the
 * module ever sees the address, so there is no call site that could
 * accidentally persist one.
 */
class SmartQrScanFingerprint
{
    /**
     * How long two hits from the same fingerprint count as one scan.
     *
     * ⚠️ HARD-CODED, by ruling. §10 offers "configurable time window"; a
     * configurable one means every deployment reports a different number under
     * the same column name, and comparing two installations becomes impossible.
     */
    public const UNIQUE_WINDOW_HOURS = 24;

    private function key(): string
    {
        return (string) config('app.key');
    }

    public function hashIp(?string $ip): ?string
    {
        return $this->hash($ip);
    }

    public function hashUserAgent(?string $ua): ?string
    {
        return $this->hash($ua);
    }

    /**
     * ⚠️ null in, null out — deliberately.
     *
     * Hashing the empty string would give every request with no IP the SAME
     * fingerprint, so a hundred unrelated visitors behind a proxy that strips
     * the header would collapse into one "unique" scan. A null fingerprint
     * cannot match anything, so those count individually — over-counting
     * uniques, which is the safer error for a metric nobody bills on.
     */
    private function hash(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return hash_hmac('sha256', $value, $this->key());
    }

    /**
     * The referer's HOST, or null.
     *
     * ⚠️ Host only. A full referer carries query strings, and query strings
     * carry tokens and personal data — storing one would create an obligation
     * this table should not have.
     */
    public function refererHost(?string $referer): ?string
    {
        if ($referer === null || trim($referer) === '') {
            return null;
        }

        $host = parse_url($referer, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? mb_substr($host, 0, 255) : null;
    }

    /**
     * Is this user agent an obvious bot, crawler or prefetcher?
     *
     * ⚠️ FLAGS, NEVER DROPS. §10 says not to treat preview crawlers as real
     * scans, and they are excluded from aggregates — but the row is kept.
     *
     * A WhatsApp or Facebook crawler fetching the link is evidence the link was
     * SHARED, which is signal rather than noise. And a bot that lies about its
     * user agent is counted whatever this method does, so discarding the row
     * buys less than it costs: recording is reversible, deleting is not.
     *
     * Substring matching on a short list, deliberately. Anything cleverer needs
     * a UA library this project does not have, and would be wrong in ways
     * nobody notices.
     */
    public function looksLikeBot(?string $ua): bool
    {
        if ($ua === null || trim($ua) === '') {
            // No user agent at all is not a browser. Real scanners send one.
            return true;
        }

        $needles = [
            'bot', 'crawler', 'spider', 'crawling',
            'facebookexternalhit', 'facebookcatalog', 'whatsapp',
            'slackbot', 'telegrambot', 'twitterbot', 'linkedinbot', 'discordbot',
            'preview', 'prefetch', 'monitoring', 'pingdom', 'uptimerobot',
            'headlesschrome', 'phantomjs', 'curl/', 'wget/', 'python-requests',
        ];

        $haystack = mb_strtolower($ua);

        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
