<?php

declare(strict_types=1);

namespace App\Service\Research\Spike;

/**
 * Keeps a lead only when its URL is already in the page's own link list.
 */
final class LeadUrlFilter
{
    /**
     * @param list<string> $proposed
     * @param list<string> $pageLinks
     *
     * @return list<string>
     */
    public function keep(array $proposed, array $pageLinks): array
    {
        $allowed = [];
        foreach ($pageLinks as $link) {
            $canonical = $this->canonical($link);
            if (null !== $canonical) {
                $allowed[$canonical] = true;
            }
        }

        $kept = [];
        foreach ($proposed as $url) {
            $canonical = $this->canonical($url);
            if (null === $canonical || !isset($allowed[$canonical]) || isset($kept[$canonical])) {
                continue;
            }
            $kept[$canonical] = $canonical;
        }

        return array_values($kept);
    }

    public function canonical(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';
        if ('/' === $path) {
            $path = '';
        } else {
            $path = rtrim($path, '/');
        }

        $query = isset($parts['query']) && '' !== $parts['query'] ? '?'.$parts['query'] : '';

        return $scheme.'://'.$host.$path.$query;
    }
}
