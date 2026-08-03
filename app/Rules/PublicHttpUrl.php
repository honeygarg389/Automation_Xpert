<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a URL points at a public internet host, so customer-supplied
 * URLs cannot be used to make the server fetch internal resources (SSRF).
 *
 * Used in two places, and it must stay usable from both:
 *
 *  1. As a validation rule when an endpoint URL is saved.
 *  2. Via inspect() from App\Jobs\DispatchWebhookJob immediately before the
 *     request is sent. The second call is the one that matters — a host that
 *     resolved to a public address at save time can be re-pointed at 127.0.0.1
 *     afterwards (DNS rebinding), so save-time validation alone is not a
 *     control.
 *
 * FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE is applied as a baseline but is not
 * relied upon: it does not catch CGNAT (100.64.0.0/10) and does not decode
 * IPv4-mapped IPv6 (::ffff:127.0.0.1).
 */
class PublicHttpUrl implements ValidationRule
{
    /** Ports an endpoint may use. Anything else is refused. */
    private const ALLOWED_PORTS = [443];

    /** @var list<string> IPv4 CIDRs that must never be contacted. */
    private const BLOCKED_V4 = [
        '0.0.0.0/8',        // "this network"
        '10.0.0.0/8',       // RFC1918
        '100.64.0.0/10',    // CGNAT — missed by NO_PRIV_RANGE
        '127.0.0.0/8',      // loopback
        '169.254.0.0/16',   // link-local (cloud metadata lives here)
        '172.16.0.0/12',    // RFC1918
        '192.0.0.0/24',     // IETF protocol assignments
        '192.168.0.0/16',   // RFC1918
        '198.18.0.0/15',    // benchmarking
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved, includes 255.255.255.255
    ];

    /** @var list<string> IPv6 CIDRs that must never be contacted. */
    private const BLOCKED_V6 = [
        '::/128',           // unspecified
        '::1/128',          // loopback
        'fc00::/7',         // unique local
        'fe80::/10',        // link-local
        'ff00::/8',         // multicast
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $reason = self::inspect(is_string($value) ? $value : '');

        if ($reason !== null) {
            $fail($reason);
        }
    }

    /**
     * Inspect a URL. Returns null when it is safe to fetch, or a human-readable
     * reason when it is not.
     *
     * Safe to call from a queued job: it performs DNS resolution but never
     * throws.
     */
    public static function inspect(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return 'The webhook URL is required.';
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host']) || $parts['host'] === '') {
            return 'The webhook URL is not a valid URL.';
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'https') {
            return 'The webhook URL must use https.';
        }

        if (isset($parts['port']) && ! in_array((int) $parts['port'], self::ALLOWED_PORTS, true)) {
            return 'The webhook URL must not specify a non-standard port.';
        }

        // Credentials in the URL are a redirect/proxy-confusion hazard and are
        // never needed for a webhook receiver.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'The webhook URL must not contain credentials.';
        }

        $host = trim($parts['host'], '[]');

        // A literal IP is checked directly — no DNS lookup to be tricked by.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isBlockedIp($host)
                ? 'The webhook URL must point at a public host.'
                : null;
        }

        $ips = self::resolve($host);

        if ($ips === []) {
            return 'The webhook URL host could not be resolved.';
        }

        // Every resolved address must be public — a host with one public and
        // one private record must not be accepted.
        foreach ($ips as $ip) {
            if (self::isBlockedIp($ip)) {
                return 'The webhook URL must point at a public host.';
            }
        }

        return null;
    }

    /**
     * Resolve a hostname to every A and AAAA record.
     *
     * @return list<string>
     */
    public static function resolve(string $host): array
    {
        $ips = [];

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $ips[] = $record['ip'];
                } elseif (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        // dns_get_record does not consult /etc/hosts; gethostbynamel does, and
        // covers resolvers where the above returns nothing.
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }

        return array_values(array_unique(array_filter(
            $ips,
            static fn ($ip) => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false
        )));
    }

    /** True when the address is in any range the application must not contact. */
    public static function isBlockedIp(string $ip): bool
    {
        // Decode IPv4-mapped IPv6 (::ffff:127.0.0.1) before range checks, or a
        // loopback address slips through as "a v6 address".
        $normalised = self::unmapIpv4($ip);

        if (filter_var($normalised, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            foreach (self::BLOCKED_V4 as $cidr) {
                if (self::inCidrV4($normalised, $cidr)) {
                    return true;
                }
            }

            // Baseline backstop for anything the explicit list misses.
            return filter_var(
                $normalised,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false;
        }

        if (filter_var($normalised, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            foreach (self::BLOCKED_V6 as $cidr) {
                if (self::inCidrV6($normalised, $cidr)) {
                    return true;
                }
            }

            return filter_var(
                $normalised,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false;
        }

        // Unparseable: refuse rather than guess.
        return true;
    }

    /** Return the embedded IPv4 address of a mapped/compatible v6 address. */
    private static function unmapIpv4(string $ip): string
    {
        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        // ::ffff:a.b.c.d — first 10 bytes zero, then 0xFFFF.
        if (str_starts_with($packed, str_repeat("\x00", 10)."\xff\xff")) {
            return inet_ntop(substr($packed, 12, 4)) ?: $ip;
        }

        return $ip;
    }

    private static function inCidrV4(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $bits = (int) $bits;
        if ($bits === 0) {
            return true;
        }

        $mask = -1 << (32 - $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    private static function inCidrV6(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);

        $ipPacked = @inet_pton($ip);
        $subnetPacked = @inet_pton($subnet);

        if ($ipPacked === false || $subnetPacked === false) {
            return false;
        }

        $bits = (int) $bits;
        $wholeBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;

        if ($wholeBytes > 0 && strncmp($ipPacked, $subnetPacked, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainingBits) & 0xFF;

        return (ord($ipPacked[$wholeBytes]) & $mask) === (ord($subnetPacked[$wholeBytes]) & $mask);
    }
}
