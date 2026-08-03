<?php

namespace Tests\Unit\Rules;

use App\Rules\PublicHttpUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Table-driven cover for the SSRF guard.
 *
 * Extends PHPUnit's TestCase directly rather than Tests\TestCase: these are
 * pure functions, so the test needs no application container and — importantly —
 * no database, which keeps it runnable even while the suite's database
 * isolation is unresolved.
 */
class PublicHttpUrlTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function blockedUrls(): array
    {
        return [
            'loopback v4'              => ['https://127.0.0.1/hook'],
            'loopback v4 alt'          => ['https://127.1.2.3/hook'],
            'cloud metadata'           => ['https://169.254.169.254/latest/meta-data/'],
            'link-local v4'            => ['https://169.254.1.1/hook'],
            'rfc1918 10/8'             => ['https://10.0.0.1/hook'],
            'rfc1918 172.16/12'        => ['https://172.16.0.1/hook'],
            'rfc1918 192.168/16'       => ['https://192.168.1.1/hook'],
            'cgnat 100.64/10'          => ['https://100.64.0.1/hook'],
            'this-network 0.0.0.0/8'   => ['https://0.0.0.0/hook'],
            'multicast'                => ['https://224.0.0.1/hook'],
            'broadcast'                => ['https://255.255.255.255/hook'],
            'loopback v6'              => ['https://[::1]/hook'],
            'unspecified v6'           => ['https://[::]/hook'],
            'link-local v6'            => ['https://[fe80::1]/hook'],
            'unique local v6'          => ['https://[fc00::1]/hook'],
            'ipv4-mapped loopback'     => ['https://[::ffff:127.0.0.1]/hook'],
            'ipv4-mapped metadata'     => ['https://[::ffff:169.254.169.254]/hook'],
            'ipv4-mapped rfc1918'      => ['https://[::ffff:10.0.0.1]/hook'],
            'plain http'               => ['http://example.com/hook'],
            'non-standard port'        => ['https://example.com:8080/hook'],
            'credentials in url'       => ['https://user:pass@example.com/hook'],
            'not a url'                => ['not-a-url'],
            'empty'                    => [''],
            'no host'                  => ['https:///hook'],
            'file scheme'              => ['file:///etc/passwd'],
            'gopher scheme'            => ['gopher://127.0.0.1:6379/_INFO'],
        ];
    }

    #[DataProvider('blockedUrls')]
    public function test_it_blocks_non_public_urls(string $url): void
    {
        $this->assertNotNull(
            PublicHttpUrl::inspect($url),
            "Expected [{$url}] to be rejected, but it was allowed."
        );
    }

    /** @return array<string, array{0: string}> */
    public static function allowedUrls(): array
    {
        return [
            'public https'          => ['https://example.com/hook'],
            'explicit port 443'     => ['https://example.com:443/hook'],
            'with query string'     => ['https://example.com/hook?a=1&b=2'],
            'subdomain'             => ['https://hooks.example.com/receive'],
        ];
    }

    /**
     * These require working DNS — example.com must resolve. Skipped rather than
     * failed when the host is offline, so an air-gapped run does not report a
     * false negative.
     */
    #[DataProvider('allowedUrls')]
    public function test_it_allows_public_https_urls(string $url): void
    {
        if (PublicHttpUrl::resolve('example.com') === []) {
            $this->markTestSkipped('DNS unavailable: example.com does not resolve in this environment.');
        }

        $this->assertNull(
            PublicHttpUrl::inspect($url),
            "Expected [{$url}] to be allowed, but it was rejected."
        );
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function ipCases(): array
    {
        return [
            '127.0.0.1'             => ['127.0.0.1', true],
            '169.254.169.254'       => ['169.254.169.254', true],
            '10.255.255.255'        => ['10.255.255.255', true],
            '172.31.255.255'        => ['172.31.255.255', true],
            '192.168.0.1'           => ['192.168.0.1', true],
            '100.127.255.255'       => ['100.127.255.255', true],
            '::1'                   => ['::1', true],
            'fe80::1'               => ['fe80::1', true],
            'fd00::1'               => ['fd00::1', true],
            '::ffff:192.168.0.1'    => ['::ffff:192.168.0.1', true],
            'garbage'               => ['not-an-ip', true],
            '8.8.8.8'               => ['8.8.8.8', false],
            '1.1.1.1'               => ['1.1.1.1', false],
            '93.184.216.34'         => ['93.184.216.34', false],
            '2606:4700::1111'       => ['2606:4700::1111', false],
            // 172.32/12 is outside RFC1918 — guards against an over-broad mask.
            '172.32.0.1'            => ['172.32.0.1', false],
            // 100.128/10 is outside CGNAT — same reason.
            '100.128.0.1'           => ['100.128.0.1', false],
        ];
    }

    #[DataProvider('ipCases')]
    public function test_ip_range_classification(string $ip, bool $expectedBlocked): void
    {
        $this->assertSame(
            $expectedBlocked,
            PublicHttpUrl::isBlockedIp($ip),
            "[{$ip}] classification did not match expectation."
        );
    }
}
