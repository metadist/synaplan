<?php

declare(strict_types=1);

namespace App\Service\Egress;

/**
 * One allowlist entry. `*.example.org` matches a single extra label.
 * `.example.org` matches that suffix. `host:443` matches that port only.
 */
final readonly class EgressRule
{
    public function __construct(
        public string $host,
        public ?int $port,
        public bool $wildcard,
        public bool $suffix,
    ) {
    }

    public static function parse(string $raw): ?self
    {
        $raw = strtolower(trim($raw));
        if ('' === $raw || str_contains($raw, '://') || str_contains($raw, '/')) {
            return null;
        }
        $port = null;
        if (str_contains($raw, ':') && !str_starts_with($raw, '[')) {
            $pieces = explode(':', $raw);
            $maybePort = array_pop($pieces);
            if (!is_numeric($maybePort)) {
                return null;
            }
            $port = (int) $maybePort;
            $raw = implode(':', $pieces);
            if ($port < 1 || $port > 65535 || '' === $raw) {
                return null;
            }
        }
        $wildcard = str_starts_with($raw, '*.');
        $suffix = str_starts_with($raw, '.') && !$wildcard;
        $host = $wildcard ? substr($raw, 2) : $raw;
        if ('' === $host || str_contains($host, '*')) {
            return null;
        }

        return new self($host, $port, $wildcard, $suffix);
    }

    public function matches(EgressDestination $destination): bool
    {
        if (null !== $this->port && $this->port !== $destination->port) {
            return false;
        }
        if ($this->suffix) {
            return $destination->host === ltrim($this->host, '.')
                || str_ends_with($destination->host, $this->host);
        }
        if ($this->wildcard) {
            $suffix = '.'.$this->host;
            if (!str_ends_with($destination->host, $suffix)) {
                return false;
            }
            $label = substr($destination->host, 0, -\strlen($suffix));

            return '' !== $label && !str_contains($label, '.');
        }

        return $destination->host === $this->host;
    }

    public function label(): string
    {
        $host = ($this->wildcard ? '*.' : '').$this->host;

        return null === $this->port ? $host : $host.':'.$this->port;
    }
}
