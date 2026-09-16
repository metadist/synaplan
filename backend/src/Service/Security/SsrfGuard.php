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

    /**
     * Public A/AAAA (or the literal IP) after the same private/reserved filter
     * as {@see isBlockedHost}. Empty when the name does not resolve or every
     * answer is blocked. Callers that already passed {@see isBlockedHost} get
     * the pin list compute may connect to.
     *
     * @return list<string>
     */
    public function pinnedIps(string $host): array
    {
        $host = strtolower(trim($host, "[] \t"));
        if ('' === $host) {
            return [];
        }
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            return $this->isBlockedIp($host) ? [] : [$host];
        }
        $ips = [];
        foreach ($this->resolveIps($host) as $ip) {
            if (!$this->isBlockedIp($ip)) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * True when the IP is private, loopback, link-local, reserved or otherwise
     * not globally routable.
     */
    public function isBlockedIp(string $ip): bool
    {
        // NO_PRIV_RANGE: RFC 1918 + IPv6 ULA. NO_RES_RANGE: loopback,
        // link-local, 0.0.0.0/8, 240/4 and friends. GLOBAL_RANGE (PHP ≥ 8.2)
        // additionally rejects everything IANA lists as not globally
        // reachable — shared address space 100.64/10 (CGNAT, overlay VPNs),
        // 192.0.0/24, benchmarking 198.18/15, documentation prefixes and
        // 6to4 2002::/16, which the two older flags let through.
        return false === filter_var(
            $ip,
            \FILTER_VALIDATE_IP,
            \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE | \FILTER_FLAG_GLOBAL_RANGE,
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
