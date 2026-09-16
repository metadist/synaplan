<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\RateLimitService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1876: the 1.3 bytes/token heuristic overstated every language by
 * 2–3× and that figure was priced into BCOST when a provider returned no
 * usage. Pin the English BPE baseline (4 bytes/token).
 */
final class RateLimitServiceEstimateTokensTest extends TestCase
{
    #[DataProvider('byteCounts')]
    public function testEstimateUsesFourBytesPerToken(int $bytes, int $expectedTokens): void
    {
        self::assertSame($expectedTokens, RateLimitService::estimateTokens($bytes));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function byteCounts(): iterable
    {
        yield 'empty' => [0, 0];
        yield 'negative' => [-10, 0];
        yield 'one byte still one token' => [1, 1];
        yield 'english thousand bytes is ~250 not ~770' => [1000, 250];
        yield 'exact multiple' => [4, 1];
        yield 'rounds up a remainder' => [5, 2];
    }
}
