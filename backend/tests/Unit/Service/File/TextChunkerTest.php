<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\TextChunker;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1111 — TextChunker collapsed any text without newlines into a single
 * oversized chunk because the line-based splitter never split an individual
 * "line" that exceeded maxChunkSize. These tests pin the sentence/word/char
 * fallback so continuous text is always split.
 */
class TextChunkerTest extends TestCase
{
    public function testSplitsLongTextWithoutNewlines(): void
    {
        $maxChunkSize = 1500;
        $overlapSize = 150;
        $chunker = new TextChunker(maxChunkSize: $maxChunkSize, overlapSize: $overlapSize, minChunkSize: 200);

        $text = str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 42);
        $text = trim($text); // ~2350 chars, no newlines

        $chunks = $chunker->chunkify($text);

        self::assertGreaterThan(1, count($chunks), 'Continuous text should split into multiple chunks');
        foreach ($chunks as $chunk) {
            // A saved chunk may carry an overlap prefix from the previous chunk,
            // so allow maxChunkSize plus the overlap (and its separator).
            self::assertLessThanOrEqual($maxChunkSize + $overlapSize + 1, strlen($chunk['content']));
            self::assertLessThan(strlen($text), strlen($chunk['content']), 'Each chunk must be smaller than the whole text');
        }
    }

    public function testNewlineDelimitedTextStillSplits(): void
    {
        $chunker = new TextChunker(maxChunkSize: 1500, overlapSize: 150, minChunkSize: 200);

        $paragraph = str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 14);
        $text = trim($paragraph)."\n".trim($paragraph)."\n".trim($paragraph);

        $chunks = $chunker->chunkify($text);

        self::assertGreaterThan(1, count($chunks));
    }

    public function testWordWithoutSpacesIsHardSplit(): void
    {
        $maxChunkSize = 100;
        $overlapSize = 10;
        $chunker = new TextChunker(maxChunkSize: $maxChunkSize, overlapSize: $overlapSize, minChunkSize: 20);

        $text = str_repeat('a', 350); // single 350-char "word", no separators

        $chunks = $chunker->chunkify($text);

        self::assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual($maxChunkSize + $overlapSize + 1, strlen($chunk['content']));
        }
    }

    public function testHardSplitPreservesMultibyteCharacters(): void
    {
        // Small budget forces the hard character-split fallback; the "word" is
        // built from 2-byte (ä) and 4-byte (😀) UTF-8 characters with no
        // separators. A byte-based split would cut mid-character and produce
        // invalid UTF-8 — see PR #1215 Copilot review.
        $chunker = new TextChunker(maxChunkSize: 10, overlapSize: 3, minChunkSize: 4);

        $text = str_repeat('ä😀', 40);

        $chunks = $chunker->chunkify($text);

        self::assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            self::assertTrue(
                mb_check_encoding($chunk['content'], 'UTF-8'),
                'Every chunk (including its overlap prefix) must be valid UTF-8'
            );
        }
    }

    public function testShortTextReturnsSingleChunk(): void
    {
        $chunker = new TextChunker(maxChunkSize: 1500, overlapSize: 150, minChunkSize: 200);

        $chunks = $chunker->chunkify('A short sentence.');

        self::assertCount(1, $chunks);
        self::assertSame('A short sentence.', $chunks[0]['content']);
    }

    public function testEmptyTextReturnsNoChunks(): void
    {
        $chunker = new TextChunker();

        self::assertSame([], $chunker->chunkify(''));
    }
}
