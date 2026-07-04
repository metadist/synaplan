<?php

declare(strict_types=1);

namespace App\Service\Security;

/**
 * Shared SSRF guard for every outbound fetch the platform makes on behalf of
 * a user (URL content extraction, the future url_fetch DAG node, the outbound
 * MCP client, …).
 *
 * One guard, one policy (release-4.0 plan 09 §2.5): private, loopback,
 * link-local and reserved targets are blocked EVERYWHERE, both by literal
 * host/IP inspection and after DNS resolution — a hostname that resolves into
 * a private range is just as blocked as the raw IP.
 *
 * Extracted from UrlContentService::isBlockedUrl() (which now delegates
 * here). On top of the original prefix lists this uses PHP's IP classifier
 * (FILTER_FLAG_NO_PRIV_RANGE | NO_RES_RANGE), which also covers IPv6
 * ULA/link-local and the reserved v4 ranges the string prefixes missed.
 */
final readonly class SsrfGuard
{
    /** Literal host names that are never legitimate outbound targets. */
    private const BLOCKED_HOSTS = ['localhost', 'localhost.localdomain', 'ip6-localhost'];

    /** Only these URL schemes may be fetched on behalf of a user. */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * True when the URL must NOT be fetched: bad/missing scheme or host, a
     * blocked literal host/IP, or a hostname resolving to a blocked IP.
     */
    public function isBlockedUrl(string $url): bool
    {
        $parsed = parse_url($url);
        if (false === $parsed) {
            return true;
        }

        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return true;
        }

        $host = (string) ($parsed['host'] ?? '');
        if ('' === $host) {
            return true;
        }

        return $this->isBlockedHost($host);
    }

    /**
     * True when the host (name or literal IP) is a blocked target, including
     * after DNS resolution.
     */
    public function isBlockedHost(string $host): bool
    {
        $host = strtolower(trim($host, "[] \t"));
        if ('' === $host) {
            return true;
        }

        if (in_array($host, self::BLOCKED_HOSTS, true) || str_ends_with($host, '.localhost')) {
            return true;
        }

        // Literal IP (v4 or v6)?
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            return $this->isBlockedIp($host);
        }

        // Hostname → resolve every A/AAAA record; ANY private/reserved answer
        // blocks the target (DNS-rebinding style setups often mix records).
        foreach ($this->resolveIps($host) as $ip) {
            if ($this->isBlockedIp($ip)) {
                return true;
            }
        }

        return false;
    }

    /** True when the IP is private, loopback, link-local or otherwise reserved. */
    public function isBlockedIp(string $ip): bool
    {
        // filter_var flags: NO_PRIV_RANGE blocks RFC1918 + IPv6 ULA,
        // NO_RES_RANGE blocks loopback, link-local, 0.0.0.0/8, benchmarking
        // and other reserved ranges (v4 + v6).
        return false === filter_var(
            $ip,
            \FILTER_VALIDATE_IP,
            \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE,
        );
    }

    /**
     * Resolve a hostname to all its A + AAAA records. Failures resolve to an
     * empty list — an unresolvable host will fail the fetch itself; the guard
     * only has to catch hosts that resolve INTO a blocked range.
     *
     * @return list<string>
     */
    private function resolveIps(string $host): array
    {
        $ips = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }

        $aaaa = @dns_get_record($host, \DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (is_string($record['ipv6'] ?? null) && '' !== $record['ipv6']) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
