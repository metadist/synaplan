<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\TextChunker;
use PHPUnit\Framework\TestCase;

final class TextChunkerMarkdownTest extends TestCase
{
    public function testHeadingBreadcrumbPrefixesChunks(): void
    {
        $chunker = new TextChunker(maxChunkSize: 200, overlapSize: 20, minChunkSize: 10);
        $markdown = <<<'MD'
# Report
## Q3
### Revenue by region
EMEA booked 4.2 million this quarter after a strong September.
MD;
        $chunks = $chunker->chunkifyMarkdown($markdown);

        self::assertNotEmpty($chunks);
        self::assertStringStartsWith('Report › Q3 › Revenue by region', $chunks[0]['content']);
        self::assertStringContainsString('EMEA booked', $chunks[0]['content']);
    }

    public function testTableStaysWholeWhenItFits(): void
    {
        $chunker = new TextChunker(maxChunkSize: 400, overlapSize: 20, minChunkSize: 10);
        $markdown = <<<'MD'
# Report
| Region | Q3 |
| --- | --- |
| EMEA | 4.2 |
| APAC | 1.1 |
MD;
        $chunks = $chunker->chunkifyMarkdown($markdown);

        self::assertCount(1, $chunks);
        self::assertStringContainsString('| Region | Q3 |', $chunks[0]['content']);
        self::assertStringContainsString('| APAC | 1.1 |', $chunks[0]['content']);
    }

    public function testOversizedTableRepeatsHeaderRow(): void
    {
        $chunker = new TextChunker(maxChunkSize: 80, overlapSize: 10, minChunkSize: 10);
        $rows = [];
        for ($i = 1; $i <= 12; ++$i) {
            $rows[] = '| R'.$i.' | value-'.$i.' |';
        }
        $markdown = "# T\n| Region | Amount |\n| --- | --- |\n".implode("\n", $rows)."\n";
        $chunks = $chunker->chunkifyMarkdown($markdown);

        self::assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) {
            self::assertStringContainsString('| Region | Amount |', $chunk['content']);
        }
    }

    public function testShortHeadingSectionIsMergedNotDropped(): void
    {
        $chunker = new TextChunker(maxChunkSize: 400, overlapSize: 20, minChunkSize: 80);
        $markdown = <<<'MD'
# Report
## Long
This section has enough text to become its own chunk because it is well above the minimum size.
## Short
OK.
MD;
        $chunks = $chunker->chunkifyMarkdown($markdown);
        $all = implode("\n", array_column($chunks, 'content'));

        self::assertNotEmpty($chunks);
        self::assertStringContainsString('OK.', $all);
    }

    public function testPlainChunkifyPathIsUnchanged(): void
    {
        $chunker = new TextChunker(maxChunkSize: 80, overlapSize: 10, minChunkSize: 20);
        $text = "alpha line one\nbeta line two\ngamma line three\ndelta line four\nepsilon line five";

        $plain = $chunker->chunkify($text);
        self::assertNotEmpty($plain);
        self::assertSame(
            [
                ['content' => 'alpha line one', 'start_line' => 0, 'end_line' => 0],
            ],
            (new TextChunker(maxChunkSize: 20, overlapSize: 5, minChunkSize: 5))->chunkify('alpha line one'),
        );
    }
}
