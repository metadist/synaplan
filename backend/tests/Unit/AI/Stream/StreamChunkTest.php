<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Stream;

use App\AI\Stream\StreamChunk;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for issue #1067 — internal chain-of-thought must never be
 * treated as user-visible answer text.
 */
final class StreamChunkTest extends TestCase
{
    public function testPlainStringIsVisible(): void
    {
        self::assertSame('hello', StreamChunk::visibleText('hello'));
    }

    public function testContentChunkIsVisible(): void
    {
        self::assertSame('answer', StreamChunk::visibleText(['type' => 'content', 'content' => 'answer']));
    }

    public function testReasoningChunkIsDropped(): void
    {
        self::assertSame('', StreamChunk::visibleText([
            'type' => 'reasoning',
            'content' => 'The instruction: answer in German, no preamble.',
        ]));
    }

    public function testFinishChunkIsDropped(): void
    {
        self::assertSame('', StreamChunk::visibleText(['type' => 'finish', 'finish_reason' => 'stop']));
    }

    public function testUntypedArrayWithContentIsVisibleForBackwardCompatibility(): void
    {
        self::assertSame('legacy', StreamChunk::visibleText(['content' => 'legacy']));
    }

    public function testArrayWithoutContentYieldsEmptyString(): void
    {
        self::assertSame('', StreamChunk::visibleText(['metadata' => ['foo' => 'bar']]));
    }

    public function testNonStringContentYieldsEmptyString(): void
    {
        self::assertSame('', StreamChunk::visibleText(['type' => 'content', 'content' => ['nested' => true]]));
    }
}
