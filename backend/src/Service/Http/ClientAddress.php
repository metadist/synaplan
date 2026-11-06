<?php

declare(strict_types=1);

namespace App\Service\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * The one client address for sign-up limits, guest sessions and refresh tokens.
 *
 * Symfony's {@see Request::getClientIp()} already ignores `X-Forwarded-For`
 * unless the immediate peer is a trusted proxy. Cloudflare's
 * `CF-Connecting-IP` is the real client when that hop set it, and a visitor
 * can otherwise send the header themselves to pick a fresh rate-limit bucket.
 * It counts only when the peer is a trusted proxy. A list, or anything that
 * is not one IP address, is ignored.
 */
final readonly class ClientAddress
{
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
