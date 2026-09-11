<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Context;

use App\Service\Context\TokenEstimator;
use PHPUnit\Framework\TestCase;

final class TokenEstimatorTest extends TestCase
{
    private TokenEstimator $estimator;

    protected function setUp(): void
    {
        $this->estimator = new TokenEstimator();
    }

    public function testEmptyTextIsZeroTokens(): void
    {
        self::assertSame(0, $this->estimator->estimate(''));
        self::assertSame(0, $this->estimator->charsForTokens(0));
    }

    public function testProseUsesProseRatio(): void
    {
        $text = str_repeat('The quick brown fox jumps over the lazy dog. ', 40);

        self::assertSame(TokenEstimator::CHARS_PER_TOKEN_PROSE, $this->estimator->charsPerToken($text));
        self::assertSame((int) ceil(mb_strlen($text) / TokenEstimator::CHARS_PER_TOKEN_PROSE), $this->estimator->estimate($text));
    }

    public function testMarkdownTableIsEstimatedDenser(): void
    {
        $rows = [];
        for ($i = 1; $i <= 50; ++$i) {
            $rows[] = sprintf('| %d | 2025-01-%02d | %d.%02d | %d |', $i, $i % 28 + 1, $i * 37, $i % 100, $i * 3);
        }
        $table = "| ID | Date | Revenue | Units |\n| --- | --- | --- | --- |\n".implode("\n", $rows);

        self::assertSame(TokenEstimator::CHARS_PER_TOKEN_TABLE, $this->estimator->charsPerToken($table));
        self::assertGreaterThan(
            (int) ceil(mb_strlen($table) / TokenEstimator::CHARS_PER_TOKEN_PROSE),
            $this->estimator->estimate($table),
        );
    }

    public function testCjkTextIsEstimatedDensest(): void
    {
        $text = str_repeat('東京都は日本の首都であり、政治と経済の中心です。', 20);

        self::assertSame(TokenEstimator::CHARS_PER_TOKEN_CJK, $this->estimator->charsPerToken($text));
    }

    public function testCharsForTokensIsInverseOfEstimateWithinRounding(): void
    {
        $sample = str_repeat('Lorem ipsum dolor sit amet. ', 30);
        $chars = $this->estimator->charsForTokens(1000, $sample);

        self::assertSame(3500, $chars);
        self::assertLessThanOrEqual(1000, $this->estimator->estimate(mb_substr($sample.$sample.$sample.$sample.$sample, 0, $chars)));
    }
}
