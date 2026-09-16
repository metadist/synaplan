<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Context;

use App\Service\Context\ContextChunker;
use PHPUnit\Framework\TestCase;

final class ContextChunkerTest extends TestCase
{
    private ContextChunker $chunker;

    protected function setUp(): void
    {
        $this->chunker = new ContextChunker();
    }

    public function testShortTextIsASingleChunk(): void
    {
        self::assertSame(['hello world'], $this->chunker->split("  hello world \n", 1000));
        self::assertSame([], $this->chunker->split("   \n ", 1000));
    }

    public function testSplitsOnSheetHeadingsFirst(): void
    {
        $sheetA = "## Sheet: Sales\n\n".str_repeat("Row of sales data here.\n", 30);
        $sheetB = "## Sheet: Regions\n\n".str_repeat("Region line.\n", 30);

        $chunks = $this->chunker->split($sheetA.$sheetB, 900);

        self::assertGreaterThanOrEqual(2, count($chunks));
        self::assertStringStartsWith('## Sheet: Sales', $chunks[0]);
        $regionChunks = array_values(array_filter($chunks, static fn (string $c): bool => str_starts_with($c, '## Sheet: Regions')));
        self::assertCount(1, $regionChunks, 'each heading opens a new chunk');
    }

    public function testMarkdownTableHeaderIsRepeatedOnContinuationChunks(): void
    {
        $header = "| ID | Region | Revenue |\n| --- | --- | --- |";
        $rows = [];
        for ($i = 1; $i <= 200; ++$i) {
            $rows[] = sprintf('| %d | Region-%d | %d.00 |', $i, $i % 5, $i * 1000);
        }
        $table = "## Sheet: Sales\n\n".$header."\n".implode("\n", $rows);

        $chunks = $this->chunker->split($table, 1500);

        self::assertGreaterThan(2, count($chunks));
        self::assertStringStartsWith('## Sheet: Sales', $chunks[0]);
        foreach (array_slice($chunks, 1) as $chunk) {
            self::assertStringStartsWith($header, $chunk, 'continuation chunk must carry the column header');
            self::assertLessThanOrEqual(1500, mb_strlen($chunk));
        }

        // No row is lost or duplicated across the split.
        $joined = implode("\n", $chunks);
        foreach ($rows as $row) {
            self::assertSame(1, substr_count($joined, $row."\n") + (str_ends_with($joined, $row) ? 1 : 0), 'row must appear exactly once: '.$row);
        }
    }

    public function testRespectsMaxCharsForProse(): void
    {
        $paragraphs = [];
        for ($i = 0; $i < 40; ++$i) {
            $paragraphs[] = str_repeat("Sentence number $i in a paragraph. ", 8);
        }
        $text = implode("\n\n", $paragraphs);

        $chunks = $this->chunker->split($text, 2000);

        self::assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(2000, mb_strlen($chunk));
            self::assertNotSame('', trim($chunk));
        }
        self::assertSame(preg_replace('/\s+/', '', $text), preg_replace('/\s+/', '', implode('', $chunks)), 'content is preserved');
    }

    public function testHardCutsAnUnbreakableLine(): void
    {
        $line = str_repeat('x', 5000);

        $chunks = $this->chunker->split($line, 1200);

        self::assertCount(5, $chunks);
        self::assertSame($line, implode('', $chunks));
    }
}
