<?php

declare(strict_types=1);

namespace App\Service\SmartSearch;

/**
 * A short excerpt of a body around the first matched word.
 */
final class SnippetBuilder
{
    public const LENGTH = 160;
    private const LEAD = 40;

    /**
     * @param list<string> $terms lower-case words
     */
    public static function build(string $body, array $terms): ?string
    {
        $body = trim((string) preg_replace('/\s+/u', ' ', $body));
        if ('' === $body) {
            return null;
        }

        $haystack = mb_strtolower($body);
        $position = null;
        foreach ($terms as $term) {
            $found = mb_strpos($haystack, $term);
            if (false !== $found && (null === $position || $found < $position)) {
                $position = $found;
            }
        }

        $start = null === $position ? 0 : max(0, $position - self::LEAD);
        $excerpt = mb_substr($body, $start, self::LENGTH);
        $prefix = $start > 0 ? '…' : '';
        $suffix = $start + self::LENGTH < mb_strlen($body) ? '…' : '';

        return $prefix.trim($excerpt).$suffix;
    }
}
