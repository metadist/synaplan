<?php

declare(strict_types=1);

namespace App\Service\Http;

use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

/**
 * The one client address for sign-up limits, guest sessions and refresh tokens.
 *
 * Symfony's {@see Request::getClientIp()} already ignores `X-Forwarded-For`
 * unless the immediate peer is a trusted proxy. Cloudflare's
 * `CF-Connecting-IP` is the real client when that hop set it, and a visitor
 * can otherwise send the header themselves to pick a fresh rate-limit bucket.
 * It counts only when a Cloudflare edge in the deployment's trusted-proxy
 * chain handed the request to the application. A local proxy that merely
 * forwards the header, or a Cloudflare range that the deployment did not
 * trust, does not. A list, or anything that is not one IP address, is ignored.
 */
final readonly class ClientAddress
{
    private const RESOLVED_ATTRIBUTE = '_client_address';

    /**
     * Cached before {@see align()} replaces `X-Forwarded-For` with the visitor,
     * so a later {@see cameThroughTrustedCloudflare()} call still sees the edge hop.
     */
    private const CLOUDFLARE_HOP_ATTRIBUTE = '_client_address_cloudflare_hop';

    /**
     * Published Cloudflare edges (https://www.cloudflare.com/ips/).
     *
     * @var list<string>
     */
    private const CLOUDFLARE_RANGES = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    public static function resolve(Request $request): ?string
    {
        $cached = $request->attributes->get(self::RESOLVED_ATTRIBUTE);
        if (is_string($cached) && '' !== $cached) {
            return $cached;
        }

        $cloudflare = self::trustedCloudflareIp($request);
        if (null !== $cloudflare) {
            $request->attributes->set(self::RESOLVED_ATTRIBUTE, $cloudflare);

            return $cloudflare;
        }

        $ip = self::safeClientIp($request);

        return is_string($ip) && '' !== $ip ? $ip : null;
    }

    /**
     * Make {@see Request::getClientIp()} return {@see resolve()} for the rest
     * of this request, when the deployment trusts `X-Forwarded-For`.
     *
     * Call this before anything reads the client address. It does not enable
     * forwarding on its own: an operator who has not trusted the header keeps
     * Symfony's current rule, and {@see resolve()} is what the limits use.
     */
    public static function align(Request $request): void
    {
        $cloudflare = self::trustedCloudflareIp($request);
        if (null === $cloudflare) {
            return;
        }

        $request->attributes->set(self::RESOLVED_ATTRIBUTE, $cloudflare);

        if (0 === (Request::getTrustedHeaderSet() & Request::HEADER_X_FORWARDED_FOR)) {
            return;
        }

        if ($request->headers->get('X-Forwarded-For') === $cloudflare) {
            return;
        }

        $request->headers->set('X-Forwarded-For', $cloudflare);
        if (0 !== (Request::getTrustedHeaderSet() & Request::HEADER_FORWARDED) && $request->headers->has('Forwarded')) {
            $request->headers->remove('Forwarded');
        }
    }

    /**
     * True only when at least one hop in the request's contiguous trusted-proxy
     * suffix is both trusted by the deployment (`SYMFONY_TRUSTED_PROXIES`) and
     * a published Cloudflare edge.
     *
     * Every hop between `REMOTE_ADDR` and that Cloudflare edge must also sit in
     * `SYMFONY_TRUSTED_PROXIES` (for example the worker-pool loopback, the edge
     * reverse proxy and the load balancer). A local proxy that does not trust
     * Cloudflare ranges, an untrusted neighbour container, or a Cloudflare
     * address left of an untrusted hop is refused.
     */
    public static function cameThroughTrustedCloudflare(Request $request): bool
    {
        $cached = $request->attributes->get(self::CLOUDFLARE_HOP_ATTRIBUTE);
        if (is_bool($cached)) {
            return $cached;
        }

        $viaCloudflare = self::walkTrustedCloudflareSuffix($request);
        $request->attributes->set(self::CLOUDFLARE_HOP_ATTRIBUTE, $viaCloudflare);

        return $viaCloudflare;
    }

    private static function trustedCloudflareIp(Request $request): ?string
    {
        if (!self::cameThroughTrustedCloudflare($request)) {
            return null;
        }

        $header = $request->headers->get('CF-Connecting-IP');
        if (!is_string($header)) {
            return null;
        }

        $header = trim($header);
        if (
            '' === $header
            || str_contains($header, ',')
            || false === filter_var($header, FILTER_VALIDATE_IP)
            || IpUtils::checkIp($header, Request::getTrustedProxies())
        ) {
            return null;
        }

        return $header;
    }

    private static function walkTrustedCloudflareSuffix(Request $request): bool
    {
        $trusted = Request::getTrustedProxies();
        if ([] === $trusted || !$request->isFromTrustedProxy()) {
            return false;
        }

        $chain = self::forwardingChain($request);

        for ($i = count($chain) - 1; $i >= 0; --$i) {
            $hop = $chain[$i];
            if (null === $hop || !IpUtils::checkIp($hop, $trusted)) {
                return false;
            }
            if (IpUtils::checkIp($hop, self::CLOUDFLARE_RANGES)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Non-empty malformed tokens stay in the chain as `null` so a garbage value
     * breaks the contiguous trusted suffix instead of bridging over an untrusted hop.
     *
     * @return list<?string>
     */
    private static function forwardingChain(Request $request): array
    {
        $chain = [];
        $trustsForwardedFor = 0 !== (Request::getTrustedHeaderSet() & Request::HEADER_X_FORWARDED_FOR);
        if ($trustsForwardedFor && $request->headers->has('X-Forwarded-For')) {
            foreach (explode(',', (string) $request->headers->get('X-Forwarded-For')) as $part) {
                if ('' === trim($part)) {
                    continue;
                }
                $chain[] = self::normalizeHop($part);
            }
        }

        $remote = $request->server->get('REMOTE_ADDR');
        if (is_string($remote) && '' !== trim($remote)) {
            $chain[] = self::normalizeHop($remote);
        }

        return $chain;
    }

    private static function normalizeHop(string $raw): ?string
    {
        $ip = trim($raw);
        if (str_starts_with($ip, '[')) {
            $end = strpos($ip, ']', 1);
            $ip = false === $end ? '' : substr($ip, 1, $end - 1);
        } elseif (str_contains($ip, '.') && 1 === substr_count($ip, ':')) {
            $ip = (string) strstr($ip, ':', true);
        }

        return false !== filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    private static function safeClientIp(Request $request): ?string
    {
        try {
            return $request->getClientIp();
        } catch (\Throwable) {
            $remote = $request->server->get('REMOTE_ADDR');

            return is_string($remote) && '' !== $remote ? $remote : null;
        }
    }
}
