<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider\Google;

use App\AI\Provider\Google\GoogleUsage;
use PHPUnit\Framework\TestCase;

final class GoogleUsageTest extends TestCase
{
    public function testThinkingTokensAreBilledAsOutputAndCacheStaysSeparate(): void
    {
        $usage = GoogleUsage::fromMetadata([
            'promptTokenCount' => 1000,
            'candidatesTokenCount' => 20,
            'thoughtsTokenCount' => 80,
            'cachedContentTokenCount' => 200,
        ]);

        self::assertSame(1000, $usage['prompt_tokens']);
        self::assertSame(100, $usage['completion_tokens']);
        self::assertSame(1100, $usage['total_tokens']);
        self::assertSame(200, $usage['cached_tokens']);
        self::assertSame(0, $usage['cache_creation_tokens']);
    }

    public function testAChunkWithoutUsageKeepsTheRunningTotals(): void
    {
        self::assertSame(
            [10, 4, 2],
            GoogleUsage::running(null, 10, 4, 2),
        );
    }

    public function testThoughtsWithoutAFreshCandidateCountAddToThePreviousOutput(): void
    {
        self::assertSame(
            [10, 9, 2],
            GoogleUsage::running(['thoughtsTokenCount' => 5], 10, 4, 2),
        );
    }
}
