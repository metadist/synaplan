<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Entity\PlatformInstance;
use App\Service\Security\SsrfGuard;

/**
 * Synaplan calls only a delivery address that belongs to the registered cloud.
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
        $host = strtolower($parts['host']);
        $scheme = strtolower($parts['scheme']);
        if ($this->redirects->isDev() && $this->redirects->isLocalDevHost($host) && in_array($scheme, ['http', 'https'], true)) {
            return true;
        }
        if ('https' !== $scheme) {
            return false;
        }

        return $host === $this->registeredHost($instance) && !$this->ssrfGuard->isBlockedHost($host);
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
