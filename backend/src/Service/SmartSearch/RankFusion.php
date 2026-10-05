<?php

declare(strict_types=1);

namespace App\Service\SmartSearch;

/**
 * Reciprocal Rank Fusion: score(d) = Σ 1 / (k + rank_i(d)).
 *
 * Keyword and vector scores live on different scales, so only the rank of an
 * item inside each list counts. An item found by keyword and by meaning is
 * marked `both`.
 */
final class RankFusion
{
    public const DEFAULT_K = 60;

    /**
     * @param list<list<SearchHit>> $lists each list best first
     *
     * @return list<SearchHit> fused, best first
     */
    public static function fuse(array $lists, int $k = self::DEFAULT_K): array
    {
        /** @var array<string, array{hit: SearchHit, score: float, sources: array<string, true>}> $merged */
        $merged = [];
        foreach ($lists as $list) {
            foreach ($list as $index => $hit) {
                $id = $hit->id();
                $contribution = 1.0 / ($k + $index + 1);
                if (!isset($merged[$id])) {
                    $merged[$id] = ['hit' => $hit, 'score' => 0.0, 'sources' => []];
                } elseif (null === $merged[$id]['hit']->snippet && null !== $hit->snippet) {
                    $merged[$id]['hit'] = $hit;
                }
                $merged[$id]['score'] += $contribution;
                $merged[$id]['sources'][$hit->matchedBy] = true;
            }
        }

        $fused = array_map(static function (array $entry): SearchHit {
            $sources = $entry['sources'];
            $matchedBy = isset($sources[SearchHit::MATCHED_BOTH])
                || (isset($sources[SearchHit::MATCHED_LEXICAL]) && isset($sources[SearchHit::MATCHED_SEMANTIC]))
                ? SearchHit::MATCHED_BOTH
                : (string) array_key_first($sources);

            return $entry['hit']->with($entry['score'], $matchedBy);
        }, array_values($merged));

        usort($fused, static fn (SearchHit $a, SearchHit $b): int => $b->score <=> $a->score);

        return $fused;
    }
}
