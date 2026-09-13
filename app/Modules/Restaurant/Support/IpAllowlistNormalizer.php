<?php

namespace App\Modules\Restaurant\Support;

/**
 * Normalizes exact-IP allowlist input BEFORE validation runs: accepts either
 * an array or raw newline/comma-separated text, trims whitespace, drops
 * empty entries (a blank line must never count as an invalid IP), and
 * deduplicates.
 *
 * Used by both the create-connection form and the allowed-ips update action
 * so "1.2.3.4\n1.2.3.4 \n\n5.6.7.8" and ["1.2.3.4", " 1.2.3.4", "5.6.7.8"]
 * both normalize to the identical two-entry result, regardless of which
 * shape a caller (the JS form, a direct API call, a test) happens to send.
 */
class IpAllowlistNormalizer
{
    /**
     * @return list<string>|null
     */
    public static function normalize(mixed $input): ?array
    {
        if ($input === null) {
            return null;
        }

        $items = is_array($input) ? $input : preg_split('/[\n,]+/', (string) $input);

        $cleaned = collect($items)
            ->map(fn ($ip) => is_string($ip) ? trim($ip) : $ip)
            ->filter(fn ($ip) => $ip !== null && $ip !== '')
            ->unique()
            ->values()
            ->all();

        return $cleaned === [] ? null : $cleaned;
    }
}
