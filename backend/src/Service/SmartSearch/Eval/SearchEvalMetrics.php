<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Eval;

/**
 * Ranking metrics for `app:search:eval`, with binary relevance: an id is
 * either one of the expected targets or not.
 */
final class SearchEvalMetrics
{
    /**
     * Share of the expected ids that appear in the first $k results, capped
     * at $k: with more targets than slots, a full first page counts as 1.0.
     *
     * @param list<string> $ranked   result ids, best first
     * @param list<string> $expected relevant ids
     */
    public static function recallAt(array $ranked, array $expected, int $k): float
    {
        $expected = array_unique($expected);
        if ([] === $expected) {
            return 1.0;
        }
        $found = array_intersect(array_slice($ranked, 0, $k), $expected);

        return count(array_unique($found)) / min($k, count($expected));
    }

    /**
     * Normalised discounted cumulative gain over the first $k results; 1.0
     * when every expected id sits at the top.
     *
     * @param list<string> $ranked   result ids, best first
     * @param list<string> $expected relevant ids
     */
    public static function ndcgAt(array $ranked, array $expected, int $k): float
    {
        $relevant = array_flip(array_unique($expected));
        if ([] === $relevant) {
            return 1.0;
        }

        $dcg = 0.0;
        foreach (array_slice($ranked, 0, $k) as $index => $id) {
            if (isset($relevant[$id])) {
                $dcg += 1.0 / log($index + 2, 2);
            }
        }
        $ideal = 0.0;
        for ($index = 0, $max = min($k, count($relevant)); $index < $max; ++$index) {
            $ideal += 1.0 / log($index + 2, 2);
        }

        return $dcg / $ideal;
    }

    /**
     * 1-based position of the first expected id in the first $k results.
     *
     * @param list<string> $ranked
     * @param list<string> $expected
     */
    public static function firstRank(array $ranked, array $expected, int $k): ?int
    {
        foreach (array_slice($ranked, 0, $k) as $index => $id) {
            if (in_array($id, $expected, true)) {
                return $index + 1;
            }
        }

        return null;
    }
}
