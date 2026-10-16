<?php

declare(strict_types=1);

namespace App\Service\Federation;

use App\Service\Security\SsrfGuard;

/**
 * Outbound partner URLs are https and public. This server's own address is
 * judged without a DNS lookup: localhost and private addresses cannot be
 * opened to partners unless the dev allow-local flag is on.
 */
final readonly class FederationUrlGuard
{
    public function __construct(
        private SsrfGuard $ssrf,
        private bool $allowLocal,
    ) {
    }

    public function isReachableAppUrl(string $url): bool
    {
        $parts = $this->parts($url);
        if (null === $parts) {
            return false;
        }
        if ($this->allowLocal) {
            return true;
        }
        if ('https' !== $parts['scheme'] || $this->isLocalName($parts['host'])) {
            return false;
        }
        if (false !== filter_var($parts['host'], FILTER_VALIDATE_IP)) {
            return !$this->ssrf->isBlockedIp($parts['host']);
        }

        return true;
    }

    public function assertOutbound(string $url): void
    {
        $parts = $this->parts($url);
        if (null === $parts) {
            throw new FederationException('bad_url', 'That address is not a valid URL.');
        }
        if ($this->allowLocal) {
            return;
        }
        if ('https' !== $parts['scheme'] || $this->ssrf->isBlockedUrl($url) || [] === $this->ssrf->pinnedIps($parts['host'])) {
            throw new FederationException('bad_url', 'That address is not a public https server.');
        }
    }

    /**
     * @return array{scheme: string, host: string}|null
     */
    private function parts(string $url): ?array
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ('' === $host || !in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return ['scheme' => $scheme, 'host' => $host];
    }

    private function isLocalName(string $host): bool
    {
        return in_array($host, ['localhost', '0.0.0.0', '::1', '[::1]'], true)
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || str_ends_with($host, '.localhost');
    }
}
