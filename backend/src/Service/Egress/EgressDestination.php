<?php

declare(strict_types=1);

namespace App\Service\Egress;

/**
 * One outbound host, as the allowlist and the metrics see it: scheme, host
 * and port. The label is `host:port` and never a path, query or userinfo.
 */
final readonly class EgressDestination
{
    public function __construct(
        public string $scheme,
        public string $host,
        public int $port,
    ) {
    }

    public static function fromUrl(string $url): ?self
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['host']) || '' === $parts['host']) {
            return null;
        }
        $scheme = strtolower(\is_string($parts['scheme'] ?? null) ? $parts['scheme'] : 'https');
        $host = strtolower(trim($parts['host'], '[]'));
        if ('' === $host) {
            return null;
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : ('http' === $scheme ? 80 : 443);
        if ($port < 1) {
            return null;
        }

        return new self($scheme, $host, $port);
    }

    public function label(): string
    {
        return $this->host.':'.$this->port;
    }
}
