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
 * It counts only when the address in front of the trusted proxies is a
 * Cloudflare edge. A proxy that merely forwards the header, such as the
 * local Caddy hop, does not. A list, or anything that is not one IP address,
 * is ignored.
 */
final readonly class ClientAddress
{
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
        $cloudflare = self::trustedCloudflareIp($request);
        if (null !== $cloudflare) {
            return $cloudflare;
        }

        $ip = $request->getClientIp();

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
        if (0 === (Request::getTrustedHeaderSet() & Request::HEADER_X_FORWARDED_FOR)) {
            return;
        }

        $cloudflare = self::trustedCloudflareIp($request);
        if (null === $cloudflare || $request->headers->get('X-Forwarded-For') === $cloudflare) {
            return;
        }

        $request->headers->set('X-Forwarded-For', $cloudflare);
    }

    private static function trustedCloudflareIp(Request $request): ?string
    {
        if (!$request->isFromTrustedProxy()) {
            return null;
        }

        // The peer Symfony stops at is who spoke to the trusted proxies.
        // Caddy on 127.0.0.1 is trusted and forwards this header unchanged,
        // so a visitor can set it. Only a Cloudflare edge actually writes it.
        $peer = $request->getClientIp();
        if (!is_string($peer) || !IpUtils::checkIp($peer, self::CLOUDFLARE_RANGES)) {
            return null;
        }

        $header = $request->headers->get('CF-Connecting-IP');
        if (!is_string($header)) {
            return null;
        }

        $header = trim($header);
        if ('' === $header || str_contains($header, ',') || false === filter_var($header, FILTER_VALIDATE_IP)) {
            return null;
        }

        return $header;
    }
}
