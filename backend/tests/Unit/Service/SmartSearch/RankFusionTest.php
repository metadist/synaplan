<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SmartSearch;

use App\Service\SmartSearch\RankFusion;
use App\Service\SmartSearch\SearchHit;
use PHPUnit\Framework\TestCase;

final class RankFusionTest extends TestCase
{
    public function testSingleListKeepsOrderWithReciprocalScores(): void
    {
        $fused = RankFusion::fuse([[$this->hit('a'), $this->hit('b')]]);

        self::assertSame(['chat:a', 'chat:b'], array_map(static fn (SearchHit $hit): string => $hit->id(), $fused));
        self::assertEqualsWithDelta(1 / 61, $fused[0]->score, 1e-9);
        self::assertEqualsWithDelta(1 / 62, $fused[1]->score, 1e-9);
    }

    public function testItemInBothListsRanksFirstAndIsMarkedBoth(): void
    {
        $lexical = [$this->hit('a'), $this->hit('shared')];
        $semantic = [$this->hit('b', SearchHit::MATCHED_SEMANTIC), $this->hit('shared', SearchHit::MATCHED_SEMANTIC)];

        $fused = RankFusion::fuse([$lexical, $semantic]);

        self::assertSame('chat:shared', $fused[0]->id());
        self::assertSame(SearchHit::MATCHED_BOTH, $fused[0]->matchedBy);
        self::assertEqualsWithDelta(2 / 62, $fused[0]->score, 1e-9);
        self::assertSame(SearchHit::MATCHED_SEMANTIC, $this->find($fused, 'chat:b')->matchedBy);
    }

    public function testRawScoresDoNotLeakAcrossLists(): void
    {
        $bigScore = new SearchHit('file', 'x', 'X', '/files?file=x', score: 999.0);
        $smallScore = new SearchHit('file', 'y', 'Y', '/files?file=y', SearchHit::MATCHED_SEMANTIC, score: 0.1);

        $fused = RankFusion::fuse([[$bigScore], [$smallScore]]);

        self::assertEqualsWithDelta($fused[0]->score, $fused[1]->score, 1e-9);
    }

    public function testKeepsTheSnippetWhenTheFirstListHadNone(): void
    {
        $withoutSnippet = new SearchHit('file', 'x', 'X', '/files?file=x');
        $withSnippet = new SearchHit('file', 'x', 'X', '/files?file=x', SearchHit::MATCHED_SEMANTIC, snippet: '…the passage…');

        $fused = RankFusion::fuse([[$withoutSnippet], [$withSnippet]]);

        self::assertSame('…the passage…', $fused[0]->snippet);
    }

    public function testEmptyInputGivesEmptyOutput(): void
    {
        self::assertSame([], RankFusion::fuse([]));
        self::assertSame([], RankFusion::fuse([[], []]));
    }

    private function hit(string $ref, string $matchedBy = SearchHit::MATCHED_LEXICAL): SearchHit
    {
        return new SearchHit('chat', $ref, 'Chat '.$ref, '/?chat='.$ref, $matchedBy);
    }

    /**
     * @param list<SearchHit> $hits
     */
    private function find(array $hits, string $id): SearchHit
    {
        foreach ($hits as $hit) {
            if ($hit->id() === $id) {
                return $hit;
            }
        }
        self::fail('Missing hit '.$id);
    }
}
