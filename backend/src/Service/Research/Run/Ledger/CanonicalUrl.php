<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Ledger;

/**
 * The same page, written two ways, is one source. Matches the spike's lead
 * filter: scheme and host are lower case, a trailing slash is dropped, the
 * port stays, and only http(s) is kept.
 */
final class CanonicalUrl
{
    public static function key(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) $parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = $parts['path'] ?? '';
        if ('/' === $path) {
            $path = '';
        } else {
            $path = rtrim((string) $path, '/');
        }

        $query = isset($parts['query']) && '' !== $parts['query'] ? '?'.$parts['query'] : '';

        return $scheme.'://'.$host.$path.$query;
    }

    public static function host(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }

        return strtolower((string) $parts['host']);
    }
}
