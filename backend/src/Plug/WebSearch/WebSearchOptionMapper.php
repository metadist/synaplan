<?php

declare(strict_types=1);

namespace App\Plug\WebSearch;

/**
 * Shared mapping of Brave-shaped options onto other providers.
 */
final class WebSearchOptionMapper
{
    /**
     * @param array<string, mixed> $options
     */
    public static function count(array $options, int $default = 10): int
    {
        $raw = $options['count'] ?? $default;

        return max(1, min(20, (int) $raw));
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function language(array $options): ?string
    {
        $lang = $options['search_lang'] ?? $options['language'] ?? null;
        if (!\is_string($lang) || '' === trim($lang)) {
            return null;
        }

        return strtolower(substr(trim($lang), 0, 2));
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function site(array $options): ?string
    {
        $site = $options['site'] ?? $options['include_domains'] ?? null;
        if (\is_array($site)) {
            $first = $site[0] ?? null;

            return \is_string($first) && '' !== trim($first) ? trim($first) : null;
        }
        if (!\is_string($site) || '' === trim($site)) {
            return null;
        }

        return trim($site);
    }

    public static function applySiteFilter(string $query, ?string $site): string
    {
        if (null === $site || '' === $site || str_contains(strtolower($query), 'site:')) {
            return $query;
        }

        return $query.' site:'.$site;
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function freshness(array $options): ?string
    {
        $raw = $options['freshness'] ?? $options['time_range'] ?? null;
        if (!\is_string($raw) || '' === trim($raw)) {
            return null;
        }

        return strtolower(trim($raw));
    }

    /**
     * Brave `pd|pw|pm|py` or already-normalized day|week|month|year.
     */
    public static function timeRange(?string $freshness): ?string
    {
        return match ($freshness) {
            'pd', 'day', 'd' => 'day',
            'pw', 'week', 'w' => 'week',
            'pm', 'month', 'm' => 'month',
            'py', 'year', 'y' => 'year',
            default => null,
        };
    }

    public static function freshnessDays(?string $freshness): ?int
    {
        return match (self::timeRange($freshness)) {
            'day' => 1,
            'week' => 7,
            'month' => 30,
            'year' => 365,
            default => null,
        };
    }

    public static function startPublishedDate(?string $freshness): ?string
    {
        $days = self::freshnessDays($freshness);
        if (null === $days) {
            return null;
        }

        return (new \DateTimeImmutable(sprintf('-%d days', $days)))->format('Y-m-d');
    }

    public static function truncate(?string $text, int $maxChars): ?string
    {
        if (null === $text) {
            return null;
        }
        $text = trim($text);
        if ('' === $text) {
            return null;
        }
        if ($maxChars <= 0 || strlen($text) <= $maxChars) {
            return $text;
        }

        return substr($text, 0, $maxChars);
    }
}
