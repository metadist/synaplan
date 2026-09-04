<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\TtsTextSanitizer;
use PHPUnit\Framework\TestCase;

final class TtsTextSanitizerTest extends TestCase
{
    public function testTruncateLeavesShortTextUnchanged(): void
    {
        self::assertSame('hello', TtsTextSanitizer::truncateForSynthesis('hello'));
    }

    public function testTruncateCapsAtTheSharedLimit(): void
    {
        $text = str_repeat('ä', TtsTextSanitizer::MAX_SYNTHESIS_CHARS + 50);

        $truncated = TtsTextSanitizer::truncateForSynthesis($text);

        self::assertSame(TtsTextSanitizer::MAX_SYNTHESIS_CHARS, mb_strlen($truncated));
        self::assertSame(str_repeat('ä', TtsTextSanitizer::MAX_SYNTHESIS_CHARS), $truncated);
    }

    public function testPrepareSanitizesThenTruncates(): void
    {
        $text = '**Hello** [Memory:12] '.str_repeat('word ', 2000);

        $prepared = TtsTextSanitizer::prepareForSynthesis($text);

        self::assertStringNotContainsString('Memory', $prepared);
        self::assertStringNotContainsString('**', $prepared);
        self::assertLessThanOrEqual(TtsTextSanitizer::MAX_SYNTHESIS_CHARS, mb_strlen($prepared));
    }
}
