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

    private static function trustedCloudflareIp(Request $request): ?string
    {
        if (!self::cameThroughTrustedCloudflareHop($request)) {
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

    /**
     * True only when at least one hop in the request's contiguous trusted-proxy
     * suffix is both trusted by the deployment (`SYMFONY_TRUSTED_PROXIES`) and
     * a published Cloudflare edge.
     *
     * A local proxy on `127.0.0.1` is trusted for worker-pool forwarding, not
     * as Cloudflare; an external Cloudflare Worker whose IP was never added to
     * `SYMFONY_TRUSTED_PROXIES` is not a trusted proxy at all.
     */
    private static function cameThroughTrustedCloudflareHop(Request $request): bool
    {
        $trusted = Request::getTrustedProxies();
        if ([] === $trusted || !$request->isFromTrustedProxy()) {
            return false;
        }

        $chain = [];
        if (0 !== (Request::getTrustedHeaderSet() & Request::HEADER_X_FORWARDED_FOR) && $request->headers->has('X-Forwarded-For')) {
            foreach (explode(',', (string) $request->headers->get('X-Forwarded-For')) as $part) {
                $ip = self::normalizeHop($part);
                if (null !== $ip) {
                    $chain[] = $ip;
                }
            }
        }

        $remote = $request->server->get('REMOTE_ADDR');
        if (is_string($remote)) {
            $remoteIp = self::normalizeHop($remote);
            if (null !== $remoteIp) {
                $chain[] = $remoteIp;
            }
        }

        for ($i = count($chain) - 1; $i >= 0; --$i) {
            $hop = $chain[$i];
            if (!IpUtils::checkIp($hop, $trusted)) {
                return false;
            }
            if (IpUtils::checkIp($hop, self::CLOUDFLARE_RANGES)) {
                return true;
            }
        }

        return false;
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
