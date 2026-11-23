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

    /** A host label curl would parse as a number: decimal, octal or hex. */
    private const NUMERIC_LABEL = '/^(?:0x[0-9a-f]*|[0-9]+)$/';

    /** First 96 bits of the NAT64 well-known prefix 64:ff9b::/96. */
    private const NAT64_PREFIX = "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00";

    /**
     * IPv4 ranges {@see isOperatorNetworkIp} accepts, as [network, prefix bits].
     *
     * @var list<array{0: string, 1: int}>
     */
    private const OPERATOR_V4_RANGES = [
        ['10.0.0.0', 8],
        ['172.16.0.0', 12],
        ['192.168.0.0', 16],
        ['127.0.0.0', 8],
        ['100.64.0.0', 10],
    ];

    public function __construct(
        private HostResolver $resolver = new DnsHostResolver(),
    ) {
    }

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
     * after DNS resolution. With $allowOperatorNetwork the operator's own
     * ranges ({@see isOperatorNetworkIp}) pass, as in {@see pinnedIps}: for a
     * host the operator named in this installation's configuration, never
     * for one a caller supplied.
     */
    public function isBlockedHost(string $host, bool $allowOperatorNetwork = false): bool
    {
        $host = $this->canonicalHost($host);
        if ('' === $host) {
            return true;
        }

        if (in_array($host, self::BLOCKED_HOSTS, true) || str_ends_with($host, '.localhost')) {
            return true;
        }

        // Literal IP (v4 or v6)?
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            return $this->isBlockedIp($host, $allowOperatorNetwork);
        }

        // Hostname → resolve every A/AAAA record; ANY private/reserved answer
        // blocks the target (DNS-rebinding style setups often mix records).
        // A name without an answer is refused as well: the HTTP client may
        // read it differently than the resolver did, so there is no address
        // this guard could vouch for.
        $ips = $this->resolveIps($host);
        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip, $allowOperatorNetwork)) {
                return true;
            }
        }

        return [] === $ips;
    }

    /**
     * Every A and AAAA answer for a hostname, unfiltered. Callers that
     * approve private targets use this to refuse a name when any answer
     * lands somewhere forbidden.
     *
     * @return list<string>
     */
    public function resolvedIps(string $host): array
    {
        $host = $this->canonicalHost($host);

        return '' === $host || false !== filter_var($host, \FILTER_VALIDATE_IP) ? [] : $this->resolveIps($host);
    }

    /**
     * Public A/AAAA (or the literal IP) after the same private/reserved filter
     * as {@see isBlockedHost}. Empty when the name does not resolve or every
     * answer is blocked. Callers that already passed {@see isBlockedHost} get
     * the pin list compute may connect to.
     *
     * With $allowOperatorNetwork the ranges an operator's own network uses
     * ({@see isOperatorNetworkIp}) are kept as well. That mode is for targets
     * an administrator configures — a self-hosted AI gateway on the LAN — never
     * for a URL a user typed. Link-local (the cloud metadata range) and every
     * other reserved range stay blocked in both modes.
     *
     * @return list<string>
     */
    public function pinnedIps(string $host, bool $allowOperatorNetwork = false): array
    {
        $host = $this->canonicalHost($host);
        if ('' === $host) {
            return [];
        }
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            return $this->isBlockedIp($host, $allowOperatorNetwork) ? [] : [$host];
        }
        $ips = [];
        foreach ($this->resolveIps($host) as $ip) {
            if (!$this->isBlockedIp($ip, $allowOperatorNetwork)) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * HTTP client `resolve` option that connects a hostname to the public
     * address this guard accepted. Without it the client resolves the name a
     * second time, and a rebinding DNS answer could point it somewhere
     * private. A literal IP needs no pin. A name without a public answer
     * gets none either; {@see isBlockedUrl} has already refused it.
     *
     * @return array{resolve?: array<string, string>}
     */
    public function pinnedResolveOption(string $url): array
    {
        $host = (string) parse_url($url, \PHP_URL_HOST);
        if ('' === $host || false !== filter_var(trim($host, '[]'), \FILTER_VALIDATE_IP)) {
            return [];
        }
        $pinned = $this->pinnedIps($host);

        return [] === $pinned ? [] : ['resolve' => [$host => $pinned[0]]];
    }

    /**
     * True when the IP is private, loopback, link-local, reserved or otherwise
     * not globally routable. With $allowOperatorNetwork the operator's own
     * ranges ({@see isOperatorNetworkIp}) pass; everything else that is not
     * globally routable is still blocked.
     */
    public function isBlockedIp(string $ip, bool $allowOperatorNetwork = false): bool
    {
        if ($allowOperatorNetwork && $this->isOperatorNetworkIp($ip)) {
            return false;
        }

        return $this->isNotGloballyRoutable($ip);
    }

    /**
     * The ranges a self-hosted service legitimately lives in: RFC 1918
     * (10/8, 172.16/12, 192.168/16), loopback (127/8, ::1), IPv6 ULA
     * (fc00::/7) and shared address space 100.64/10 (CGNAT, overlay VPNs such
     * as Tailscale). Deliberately NOT included: link-local 169.254/16 and
     * fe80::/10 — that is where cloud metadata services (169.254.169.254)
     * answer — nor 0/8, multicast, broadcast, documentation or benchmarking
     * prefixes, which no real gateway uses.
     */
    public function isOperatorNetworkIp(string $ip): bool
    {
        $ip = trim($ip, "[] \t");
        if (false !== filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if (false === $long) {
                return false;
            }
            foreach (self::OPERATOR_V4_RANGES as [$network, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($long & $mask) === (ip2long($network) & $mask)) {
                    return true;
                }
            }

            return false;
        }

        if (false !== filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if (false === $packed) {
                return false;
            }
            if ($packed === inet_pton('::1')) {
                return true;
            }

            // fc00::/7 — the first byte is 0xfc or 0xfd.
            return 0xFC === (ord($packed[0]) & 0xFE);
        }

        return false;
    }

    private function isNotGloballyRoutable(string $ip): bool
    {
        $embedded = $this->nat64EmbeddedIpv4($ip);
        if (null !== $embedded) {
            $ip = $embedded;
        }

        // NO_PRIV_RANGE: RFC 1918 + IPv6 ULA. NO_RES_RANGE: loopback,
        // link-local, 0.0.0.0/8, 240/4 and friends. GLOBAL_RANGE (PHP ≥ 8.2)
        // additionally rejects everything IANA lists as not globally
        // reachable — shared address space 100.64/10 (CGNAT, overlay VPNs),
        // 192.0.0/24, benchmarking 198.18/15, documentation prefixes and
        // 6to4 2002::/16, which the two older flags let through.
        // The flags stay behind a method call. Inlined, PHPStan 2.3 on PHP 8.4
        // treats FILTER_FLAG_GLOBAL_RANGE as FILTER_THROW_ON_FAILURE, because
        // those constants share a numeric value across the 8.4/8.5 polyfill,
        // and then drops false from filter_var()'s return type.
        return false === filter_var($ip, \FILTER_VALIDATE_IP, $this->blockedIpFlags());
    }

    /**
     * The IPv4 address inside a NAT64 literal (64:ff9b::/96, RFC 6052). A
     * NAT64 gateway forwards such a packet to that IPv4 address, so the
     * embedded address decides. Null for every other address.
     */
    private function nat64EmbeddedIpv4(string $ip): ?string
    {
        $packed = false !== filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) ? inet_pton($ip) : false;
        if (false === $packed || !str_starts_with($packed, self::NAT64_PREFIX)) {
            return null;
        }
        $embedded = inet_ntop(substr($packed, 12));

        return false === $embedded ? null : $embedded;
    }

    /**
     * The host as the HTTP client will dial it: lower case, IDNA-mapped
     * (full-width digits and dots become ASCII), without the root dot.
     * Empty when it cannot be dialled as written. That includes a name whose
     * last label is a number but which is not a dotted quad (`0x7f.1`,
     * `2130706433`, `127.1`): curl reads those as IPv4 addresses, the
     * resolver does not, so the two would disagree about the target.
     */
    private function canonicalHost(string $host): string
    {
        $host = strtolower(trim($host, "[] \t"));
        if ('' === $host || str_contains($host, ':')) {
            return $host;
        }

        $ascii = idn_to_ascii($host, \IDNA_DEFAULT, \INTL_IDNA_VARIANT_UTS46);
        if (false === $ascii) {
            return '';
        }
        $host = rtrim(strtolower($ascii), '.');
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            return $host;
        }

        $dot = strrpos($host, '.');
        $lastLabel = false === $dot ? $host : substr($host, $dot + 1);

        return 1 === preg_match(self::NUMERIC_LABEL, $lastLabel) ? '' : $host;
    }

    private function blockedIpFlags(): int
    {
        return \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE | \FILTER_FLAG_GLOBAL_RANGE;
    }

    /**
     * Every A and AAAA answer for a hostname. A failed lookup is an empty
     * list, which {@see isBlockedHost} refuses.
     *
     * @return list<string>
     */
    private function resolveIps(string $host): array
    {
        return $this->resolver->addresses($host);
    }
}
