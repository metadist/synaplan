<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Document\Render;

use App\Service\Document\Model\CellModel;
use App\Service\Document\Model\ChartModel;
use App\Service\Document\Model\SheetModel;
use App\Service\Document\Model\SpreadsheetModel;
use App\Service\Document\Render\XlsxRenderer;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PHPUnit\Framework\TestCase;

final class XlsxRendererTest extends TestCase
{
    public function testRendersFormulasNumberFormatsTwoSheetsAndChart(): void
    {
        $sales = new SheetModel('Sales');
        $sales->setCell('A1', new CellModel('Month', 'string'));
        $sales->setCell('B1', new CellModel('Amount', 'string'));
        $sales->setCell('A2', new CellModel('Jan', 'string'));
        $sales->setCell('B2', new CellModel(1200.5, 'number', null, '"$"#,##0.00'));
        $sales->setCell('A3', new CellModel('Feb', 'string'));
        $sales->setCell('B3', new CellModel(980, 'number', null, '"$"#,##0.00'));
        $sales->setCell('A4', new CellModel('Total', 'string'));
        $sales->setCell('B4', new CellModel(null, 'number', '=SUM(B2:B3)', '"$"#,##0.00'));
        $sales->charts[] = new ChartModel('rev', 'bar', 'Revenue', 'A2:A3', 'B2:B3', 'D2');

        $notes = new SheetModel('Notes');
        $notes->setCell('A1', new CellModel('See Sales', 'string'));

        $model = new SpreadsheetModel([$sales, $notes], 'Sales');
        $path = sys_get_temp_dir().'/synaplan-xlsx-'.uniqid('', true).'.xlsx';

        try {
            (new XlsxRenderer())->render($model, $path);
            self::assertFileExists($path);

            $reader = new XlsxReader();
            $reader->setIncludeCharts(true);
            $loaded = $reader->load($path);
            self::assertSame(2, $loaded->getSheetCount());
            $sheet = $loaded->getSheetByName('Sales');
            self::assertNotNull($sheet);
            self::assertSame('=SUM(B2:B3)', $sheet->getCell('B4')->getValue());
            self::assertSame('"$"#,##0.00', $sheet->getCell('B2')->getStyle()->getNumberFormat()->getFormatCode());
            self::assertNotEmpty($sheet->getChartCollection());

            $roundtrip = IOFactory::load($path);
            self::assertSame('Jan', $roundtrip->getSheetByName('Sales')?->getCell('A2')->getValue());
            self::assertSame('See Sales', $roundtrip->getSheetByName('Notes')?->getCell('A1')->getValue());
            $roundtrip->disconnectWorksheets();
            $loaded->disconnectWorksheets();
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
