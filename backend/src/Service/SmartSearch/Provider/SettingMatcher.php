<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Provider;

/**
 * Keyword score of one admin setting against a query.
 *
 * The key itself is the strongest signal (`FEATURE_IAM_GROUPS_ENABLED`,
 * or the words `iam groups`), then the tab and section labels, then the
 * description. At least half of the query words must match somewhere, so a
 * sentence does not match every setting that shares one common word.
 */
final class SettingMatcher
{
    private const EXACT_KEY = 100.0;
    private const KEY_SUBSTRING = 40.0;
    private const KEY_WORD = 6.0;
    private const KEY_PREFIX = 4.0;
    private const LABEL_WORD = 3.0;
    private const DESCRIPTION_WORD = 1.0;
    private const MIN_WORD_LENGTH = 2;

    /**
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            static fn (string $word): bool => mb_strlen($word) >= self::MIN_WORD_LENGTH,
        )));
    }

    public static function score(string $query, string $key, string $labels, string $description): float
    {
        $queryWords = self::words($query);
        if ([] === $queryWords) {
            return 0.0;
        }

        $normalizedKey = strtoupper($key);
        $normalizedQuery = strtoupper(implode('_', $queryWords));
        if ($normalizedQuery === $normalizedKey) {
            return self::EXACT_KEY;
        }

        $score = str_contains($normalizedKey, $normalizedQuery) ? self::KEY_SUBSTRING : 0.0;
        $keyWords = self::words(str_replace('_', ' ', $key));
        $labelWords = self::words($labels);
        $descriptionWords = self::words($description);

        $matched = 0;
        foreach ($queryWords as $word) {
            $wordScore = match (true) {
                in_array($word, $keyWords, true) => self::KEY_WORD,
                self::hasPrefix($keyWords, $word) => self::KEY_PREFIX,
                self::hasPrefix($labelWords, $word) => self::LABEL_WORD,
                self::hasPrefix($descriptionWords, $word) => self::DESCRIPTION_WORD,
                default => 0.0,
            };
            if ($wordScore > 0) {
                ++$matched;
                $score += $wordScore;
            }
        }

        if ($matched < (int) ceil(count($queryWords) / 2)) {
            return 0.0;
        }

        return $score * ($matched / count($queryWords));
    }

    /**
     * @param list<string> $words
     */
    private static function hasPrefix(array $words, string $prefix): bool
    {
        foreach ($words as $word) {
            if (str_starts_with($word, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
