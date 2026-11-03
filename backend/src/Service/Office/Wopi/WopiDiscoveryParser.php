<?php

declare(strict_types=1);

namespace App\Service\Office\Wopi;

/**
 * Turns a Collabora `/hosting/discovery` document into extension → editor path.
 *
 * The host in `urlsrc` is whatever coolwsd saw on that request (often the
 * compose name). Callers keep only the path and prefix the browser-facing
 * public URL, so the iframe never loads an internal hostname.
 */
final class WopiDiscoveryParser
{
    /**
     * @return array<string, array{path: string, writable: bool}>
     */
    public static function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($xml);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (false === $document) {
            return [];
        }

        $best = [];
        foreach ($document->xpath('//action') ?: [] as $action) {
            $ext = strtolower(trim((string) $action['ext']));
            $name = strtolower(trim((string) $action['name']));
            $urlsrc = trim((string) $action['urlsrc']);
            if ('' === $ext || '' === $urlsrc) {
                continue;
            }
            $writable = 'edit' === $name || 'editnew' === $name;
            $rank = match ($name) {
                'edit' => 3,
                'editnew' => 2,
                'view' => 1,
                default => 0,
            };
            if ('true' === strtolower((string) $action['default'])) {
                ++$rank;
            }
            if (0 === $rank) {
                continue;
            }
            $path = self::pathOf($urlsrc);
            if (null === $path) {
                continue;
            }
            $current = $best[$ext]['rank'] ?? -1;
            if ($rank > $current) {
                $best[$ext] = ['path' => $path, 'writable' => $writable, 'rank' => $rank];
            }
        }

        $map = [];
        foreach ($best as $ext => $row) {
            $map[$ext] = ['path' => $row['path'], 'writable' => $row['writable']];
        }

        return $map;
    }

    private static function pathOf(string $urlsrc): ?string
    {
        $parts = parse_url($urlsrc);
        if (!is_array($parts)) {
            return null;
        }
        $path = $parts['path'] ?? '';
        if ('' === $path || !str_starts_with($path, '/')) {
            return null;
        }
        $query = isset($parts['query']) && '' !== $parts['query'] ? '?'.$parts['query'] : '';
        if ('' === $query && (str_ends_with($urlsrc, '?') || str_ends_with($urlsrc, '&'))) {
            $query = substr($urlsrc, -1);
        }

        return $path.$query;
    }
}
