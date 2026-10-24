<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\PlatformInstance;
use App\Service\Security\SsrfGuard;

/**
 * Synaplan calls only a delivery address that belongs to the registered
 * cloud (03 §2): the registered host over https; a hostname an
 * administrator approved, private addresses included but loopback and
 * link-local never; or, while developing, a host from the shared dev list.
 */
final readonly class DeliveryOriginPolicy
{
    public function __construct(
        private RedirectUriPolicy $redirects,
        private SsrfGuard $ssrfGuard,
    ) {
    }

    public function accepts(PlatformInstance $instance, string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !is_string($parts['host'] ?? null) || !is_string($parts['scheme'] ?? null)) {
            return false;
        }
        $host = strtolower(trim($parts['host'], '[]'));
        $scheme = strtolower($parts['scheme']);
        if ($this->redirects->isDev() && $this->redirects->isLocalDevHost($host) && in_array($scheme, ['http', 'https'], true)) {
            return true;
        }
        if (in_array($scheme, ['http', 'https'], true) && in_array($host, $instance->getApprovedHosts(), true)) {
            return !$this->isLoopbackOrLinkLocal($host);
        }
        if ('https' !== $scheme) {
            return false;
        }

        return $host === $this->registeredHost($instance) && !$this->ssrfGuard->isBlockedHost($host);
    }

    /**
     * Loopback and link-local targets are refused even for an approved
     * hostname: that is where this machine and cloud metadata services
     * answer. An approved hostname that resolves into one of them is
     * refused as well.
     */
    public function isLoopbackOrLinkLocal(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ('localhost' === $host || str_ends_with($host, '.localhost')) {
            return true;
        }
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            return self::ipIsLoopbackOrLinkLocal($host);
        }
        $ips = gethostbynamel($host);
        foreach (is_array($ips) ? $ips : [] as $ip) {
            if (self::ipIsLoopbackOrLinkLocal($ip)) {
                return true;
            }
        }

        return false;
    }

    private static function ipIsLoopbackOrLinkLocal(string $ip): bool
    {
        if (false !== filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if (false === $long) {
                return true;
            }

            // 127.0.0.0/8 loopback, 169.254.0.0/16 link-local.
            return ($long & 0xFF000000) === 0x7F000000 || ($long & 0xFFFF0000) === 0xA9FE0000;
        }
        $packed = inet_pton($ip);
        if (false === $packed) {
            return true;
        }
        if ($packed === inet_pton('::1')) {
            return true;
        }

        // fe80::/10 link-local: first byte 0xFE, second byte 0x80..0xBF.
        return 0xFE === ord($packed[0]) && (ord($packed[1]) & 0xC0) === 0x80;
    }

    private function registeredHost(PlatformInstance $instance): string
    {
        $raw = strtolower(trim($instance->getHost()));
        if (str_contains($raw, '://')) {
            $host = parse_url($raw, PHP_URL_HOST);

            return is_string($host) ? $host : '';
        }
        $host = preg_replace('/:\d+$/', '', $raw);

        return is_string($host) ? $host : '';
    }
}
