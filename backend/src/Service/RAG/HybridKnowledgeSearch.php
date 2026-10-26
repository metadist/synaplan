<?php

declare(strict_types=1);

namespace App\Service\RAG;

use App\Service\SmartSearch\RankFusion;
use App\Service\SmartSearch\SearchHit;

/**
 * Fuses a meaning list and a keyword list with the same rank fusion the
 * palette uses. A query number wins a tie, so "Art. 17" is not dropped
 * when it only came from the keyword leg.
 */
final readonly class HybridKnowledgeSearch
{
    /**
     * @param list<array<string, mixed>> $meaning
     * @param list<array<string, mixed>> $keyword
     *
     * @return list<array<string, mixed>>
     */
    public function merge(array $meaning, array $keyword, int $limit, string $query): array
    {
        if ([] === $keyword) {
            return $meaning;
        }

        /** @var array<string, array<string, mixed>> $byId */
        $byId = [];
        $lists = [];
        $lexical = $this->hits($keyword, SearchHit::MATCHED_LEXICAL, $byId);
        $semantic = $this->hits($meaning, SearchHit::MATCHED_SEMANTIC, $byId);
        if ([] !== $semantic) {
            $lists[] = $semantic;
        }
        if ([] !== $lexical) {
            $lists[] = $lexical;
        }

        $fused = RankFusion::fuse($lists);
        $numbers = self::numbers($query);
        if ([] !== $numbers) {
            usort($fused, static function (SearchHit $a, SearchHit $b) use ($numbers): int {
                $byScore = $b->score <=> $a->score;

                return 0 !== $byScore ? $byScore : self::hasNumber($b->snippet, $numbers) <=> self::hasNumber($a->snippet, $numbers);
            });
        }

        $out = [];
        foreach (array_slice($fused, 0, max(0, $limit)) as $hit) {
            if (isset($byId[$hit->refId])) {
                $out[] = $byId[$hit->refId];
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>>          $rows
     * @param array<string, array<string, mixed>> $byId
     *
     * @return list<SearchHit>
     */
    private function hits(array $rows, string $matchedBy, array &$byId): array
    {
        $hits = [];
        $seen = [];
        foreach ($rows as $row) {
            $id = (string) ($row['chunk_id'] ?? '');
            if ('' === $id || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if (!isset($byId[$id]) || SearchHit::MATCHED_SEMANTIC === $matchedBy) {
                $byId[$id] = $row;
            }
            $hits[] = new SearchHit(
                'chunk',
                $id,
                (string) ($row['file_name'] ?? 'File'),
                '',
                $matchedBy,
                null,
                (string) ($row['chunk_text'] ?? ''),
            );
        }

        return $hits;
    }

    /** @return list<string> */
    private static function numbers(string $query): array
    {
        preg_match_all('/\d+/', $query, $found);

        return array_values(array_unique($found[0]));
    }

    /** @param list<string> $numbers */
    private static function hasNumber(?string $text, array $numbers): int
    {
        $text ??= '';
        foreach ($numbers as $number) {
            if (1 === preg_match('/\b'.preg_quote($number, '/').'\b/u', $text)) {
                return 1;
            }
        }

        return 0;
    }
}
