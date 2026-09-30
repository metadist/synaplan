<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SmartSearch;

use App\Service\SmartSearch\Eval\SearchEvalMetrics;
use PHPUnit\Framework\TestCase;

final class SearchEvalMetricsTest extends TestCase
{
    public function testRecallCountsExpectedIdsInsideTheCutoff(): void
    {
        $ranked = ['a', 'b', 'c', 'd', 'e', 'f'];

        self::assertSame(1.0, SearchEvalMetrics::recallAt($ranked, ['b'], 5));
        self::assertSame(0.5, SearchEvalMetrics::recallAt($ranked, ['c', 'f'], 5));
        self::assertSame(0.0, SearchEvalMetrics::recallAt($ranked, ['z'], 5));
    }

    public function testRecallIsCappedAtTheCutoff(): void
    {
        self::assertSame(1.0, SearchEvalMetrics::recallAt(['a', 'b'], ['a', 'b', 'c'], 2));
    }

    public function testNdcgIsOneWhenTheTargetLeads(): void
    {
        self::assertSame(1.0, SearchEvalMetrics::ndcgAt(['a', 'b'], ['a'], 10));
        self::assertSame(1.0, SearchEvalMetrics::ndcgAt(['a', 'b', 'c'], ['b', 'a'], 10));
    }

    public function testNdcgFallsWithThePosition(): void
    {
        self::assertEqualsWithDelta(1 / log(3, 2), SearchEvalMetrics::ndcgAt(['x', 'a'], ['a'], 10), 1e-9);
        self::assertSame(0.0, SearchEvalMetrics::ndcgAt(['x', 'y'], ['a'], 10));
        self::assertSame(0.0, SearchEvalMetrics::ndcgAt(['x', 'a'], ['a'], 1));
    }

    public function testFirstRankIsOneBased(): void
    {
        self::assertSame(2, SearchEvalMetrics::firstRank(['x', 'a', 'b'], ['b', 'a'], 10));
        self::assertNull(SearchEvalMetrics::firstRank(['x', 'a'], ['a'], 1));
    }
}
