<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Document;

use App\Service\Document\DocumentModelMerger;
use App\Service\Document\Model\DeckModel;
use App\Service\Document\Model\SheetModel;
use App\Service\Document\Model\SpreadsheetModel;
use App\Service\Document\Model\WordBlock;
use App\Service\Document\Model\WordModel;
use PHPUnit\Framework\TestCase;

final class DocumentModelMergerTest extends TestCase
{
    public function testAppendsSheetsWithDedupedNames(): void
    {
        $base = new SpreadsheetModel([new SheetModel('Sales')], 'Sales');
        $incoming = new SpreadsheetModel([new SheetModel('Sales')], 'Sales');
        $added = (new DocumentModelMerger())->merge($base, $incoming);
        self::assertSame(1, $added);
        self::assertCount(2, $base->sheets);
        self::assertSame('Sales 2', $base->sheets[1]->name);
    }

    public function testOffsetsWordHeadingsWhenTargetIsNonEmpty(): void
    {
        $base = new WordModel([new WordBlock('a', WordBlock::TYPE_HEADING, ['text' => 'Intro', 'level' => 1])]);
        $incoming = new WordModel([new WordBlock('b', WordBlock::TYPE_HEADING, ['text' => 'More', 'level' => 1])]);
        (new DocumentModelMerger())->merge($base, $incoming);
        self::assertCount(2, $base->blocks);
        self::assertSame(2, $base->blocks[1]->payload['level']);
        self::assertNotSame('b', $base->blocks[1]->id);
    }

    public function testAppendsSlidesAndKeepsFirstTheme(): void
    {
        $base = new DeckModel([['title' => 'One', 'bullets' => []]], 'Office');
        $incoming = new DeckModel([['title' => 'Two', 'bullets' => []]], 'Dark');
        (new DocumentModelMerger())->merge($base, $incoming);
        self::assertCount(2, $base->slides);
        self::assertSame('Office', $base->theme);
    }
}
