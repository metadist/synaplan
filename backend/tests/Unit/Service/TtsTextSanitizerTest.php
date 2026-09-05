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

    public function testTruncateCutsOnTheLastSentenceEnd(): void
    {
        $sentence = str_repeat('Ein ganzer Satz. ', 300);
        $text = $sentence.str_repeat('x', 500);

        $truncated = TtsTextSanitizer::truncateForSynthesis($text);

        self::assertLessThanOrEqual(TtsTextSanitizer::MAX_SYNTHESIS_CHARS, mb_strlen($truncated));
        self::assertStringEndsWith('Satz.', $truncated, 'A voice note must not stop mid-sentence');
    }

    public function testTruncateFallsBackToTheLastWordBreak(): void
    {
        $text = str_repeat('wortohnesatzende ', 400);

        $truncated = TtsTextSanitizer::truncateForSynthesis($text);

        self::assertLessThanOrEqual(TtsTextSanitizer::MAX_SYNTHESIS_CHARS, mb_strlen($truncated));
        self::assertStringEndsWith('wortohnesatzende', $truncated, 'A voice note must not stop mid-word');
    }

    /**
     * A sentence end in the first 80% is too far back to cut at — the listener
     * would lose a fifth of the answer.
     */
    public function testTruncateIgnoresASentenceEndFarOutsideTheWindow(): void
    {
        $text = 'Kurz. '.str_repeat('wort ', 2000);

        $truncated = TtsTextSanitizer::truncateForSynthesis($text);

        self::assertGreaterThan(
            (int) (TtsTextSanitizer::MAX_SYNTHESIS_CHARS * 0.8),
            mb_strlen($truncated),
        );
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
