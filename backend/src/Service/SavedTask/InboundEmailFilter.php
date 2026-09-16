<?php

declare(strict_types=1);

namespace App\Service\SavedTask;

/**
 * Additive inbound_email filter. Absent or empty filter matches every mail (C5).
 */
final class InboundEmailFilter
{
    /**
     * @param array<string, mixed>|null $filter
     */
    public static function matches(?array $filter, string $from, string $subject, string $body): bool
    {
        if (null === $filter) {
            return true;
        }

        $fromNeedles = self::stringList($filter['from'] ?? []);
        $contains = self::stringList($filter['contains'] ?? []);
        if ([] === $fromNeedles && [] === $contains) {
            return true;
        }

        $fromOk = [] === $fromNeedles || self::fromMatches($from, $fromNeedles);
        $containsOk = [] === $contains || self::containsMatches($subject, $body, $contains, self::matchMode($filter['match'] ?? 'any'));

        if ([] !== $fromNeedles && [] !== $contains) {
            return $fromOk && $containsOk;
        }

        return $fromOk && $containsOk;
    }

    /**
     * @param list<string> $needles
     */
    private static function fromMatches(string $from, array $needles): bool
    {
        $from = strtolower(trim($from));
        foreach ($needles as $needle) {
            $needle = strtolower(trim($needle));
            if ('' === $needle) {
                continue;
            }
            if (str_starts_with($needle, '@')) {
                if (str_ends_with($from, $needle) || str_ends_with($from, substr($needle, 1))) {
                    return true;
                }
                continue;
            }
            if ($from === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $needles
     */
    private static function containsMatches(string $subject, string $body, array $needles, string $mode): bool
    {
        $haystack = strtolower($subject."\n".$body);
        $hits = 0;
        $checked = 0;
        foreach ($needles as $needle) {
            $needle = strtolower(trim($needle));
            if ('' === $needle) {
                continue;
            }
            ++$checked;
            if (str_contains($haystack, $needle)) {
                ++$hits;
            }
        }
        if (0 === $checked) {
            return true;
        }

        return 'all' === $mode ? $hits === $checked : $hits > 0;
    }

    private static function matchMode(mixed $match): string
    {
        return 'all' === $match ? 'all' : 'any';
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn ($item): bool => is_string($item) && '' !== trim($item)));
    }
}
