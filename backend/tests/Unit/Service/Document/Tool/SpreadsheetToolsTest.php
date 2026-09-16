<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Document\Tool;

use App\Service\Document\Model\CellModel;
use App\Service\Document\Tool\DocumentSession;
use App\Service\Document\Tool\ReadRangeTool;
use App\Service\Document\Tool\SetCellsTool;
use App\Service\Document\Tool\SortRangeTool;
use PHPUnit\Framework\TestCase;

final class SpreadsheetToolsTest extends TestCase
{
    public function testSetCellsUpdatesAndReadRangeReturnsThem(): void
    {
        $session = DocumentSession::empty('xlsx', 'book.xlsx');
        $set = (new SetCellsTool())->execute($session, [
            'sheet' => 'Sheet1',
            'cells' => [
                ['address' => 'A1', 'value' => 'Name'],
                ['address' => 'B1', 'value' => 10],
            ],
        ]);
        self::assertTrue($set->ok);
        self::assertTrue($set->mutates);

        $read = (new ReadRangeTool())->execute($session, ['sheet' => 'Sheet1', 'range' => 'A1:B1']);
        self::assertTrue($read->ok);
        self::assertFalse($read->mutates);
        $payload = json_decode($read->message, true);
        self::assertIsArray($payload);
        self::assertSame('Name', $payload['A1']['value']);
        self::assertSame(10, $payload['B1']['value']);
    }

    public function testUnknownSheetAndInvalidRangeAreErrors(): void
    {
        $session = DocumentSession::empty('xlsx');
        $unknown = (new ReadRangeTool())->execute($session, ['sheet' => 'Nope', 'range' => 'A1']);
        self::assertFalse($unknown->ok);
        self::assertSame('processing.documentStepUnknownSheet', $unknown->labelKey);

        $invalid = (new ReadRangeTool())->execute($session, ['sheet' => 'Sheet1', 'range' => 'not-a-range']);
        self::assertFalse($invalid->ok);
        self::assertSame('processing.documentStepInvalidRange', $invalid->labelKey);
    }

    public function testSortRangeOrdersRowsAndIgnoresMissingCells(): void
    {
        $session = DocumentSession::empty('xlsx');
        $sheet = $session->spreadsheet()?->sheet('Sheet1');
        self::assertNotNull($sheet);
        $sheet->setCell('A1', new CellModel('b', 'string'));
        $sheet->setCell('A2', new CellModel('a', 'string'));
        $result = (new SortRangeTool())->execute($session, [
            'sheet' => 'Sheet1',
            'range' => 'A1:A2',
            'column' => 'A',
        ]);
        self::assertTrue($result->ok);
        self::assertSame('a', $sheet->getCell('A1')?->value);
        self::assertSame('b', $sheet->getCell('A2')?->value);
    }
}
