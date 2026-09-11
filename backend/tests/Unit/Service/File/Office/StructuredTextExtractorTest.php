<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File\Office;

use App\Service\File\Office\StructuredTextExtractor;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Style\Color;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class StructuredTextExtractorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/structured-'.bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    public function testSpreadsheetIsSheetBySheetWithA1CoordinatesAndFormulas(): void
    {
        $spreadsheet = new Spreadsheet();
        $first = $spreadsheet->getActiveSheet();
        $first->setTitle('Sales');
        $first->setCellValue('A1', 'Item');
        $first->setCellValue('B1', 'Qty');
        $first->setCellValue('A2', 'Widget');
        $first->setCellValue('B2', 2);
        $first->setCellValue('B3', '=SUM(B2:B2)');
        $second = $spreadsheet->createSheet();
        $second->setTitle('Notes');
        $second->setCellValue('A1', 'Hello');

        $path = $this->tmpDir.'/book.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $markdown = (new StructuredTextExtractor(new NullLogger(), 500))->extract($path, 'xlsx');

        $this->assertNotNull($markdown);
        $this->assertStringContainsString('## Sales', $markdown);
        $this->assertStringContainsString('| A | B |', $markdown);
        $this->assertStringContainsString('| 1 | Item | Qty |', $markdown);
        $this->assertStringContainsString('## Notes', $markdown);
        $this->assertStringContainsString('=SUM(B2:B2)', $markdown);
    }

    public function testCapsRowsAndNotesTheRemainder(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Big');
        $sheet->setCellValue('A1', 'N');
        for ($i = 2; $i <= 6; ++$i) {
            $sheet->setCellValue('A'.$i, $i);
        }
        $path = $this->tmpDir.'/big.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $markdown = (new StructuredTextExtractor(new NullLogger(), 3))->extract($path, 'xlsx');

        $this->assertNotNull($markdown);
        $this->assertStringContainsString('| 3 |', $markdown);
        $this->assertStringNotContainsString('| 6 |', $markdown);
        $this->assertStringContainsString('3 more rows', $markdown);
    }

    public function testLargeSheetGetsAnAllRowProfileAboveTheCappedTable(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sales');
        $sheet->setCellValue('A1', 'Region');
        $sheet->setCellValue('B1', 'Revenue');
        $sheet->setCellValue('C1', 'Note');
        $regions = ['North', 'South', 'West'];
        for ($i = 2; $i <= 121; ++$i) {
            $sheet->setCellValue('A'.$i, $regions[$i % 3]);
            $sheet->setCellValue('B'.$i, ($i - 1) * 10); // 10 … 1200, sum = 72 600
        }
        $sheet->setCellValue('C2', 'only one note');
        $path = $this->tmpDir.'/sales.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $markdown = (new StructuredTextExtractor(new NullLogger(), 10))->extract($path, 'xlsx');

        $this->assertNotNull($markdown);
        $this->assertStringContainsString("## Sales\n\n### Profile\n", $markdown);
        $this->assertStringContainsString('- Rows: 121 (row 1 = header) · Columns: 3 (A–C)', $markdown);
        $this->assertStringContainsString('- Rows listed in the table below: 10 of 121', $markdown);
        $this->assertStringContainsString('A "Region": 120 values · 3 distinct text values · top: North (40), South (40), West (40)', $markdown);
        $this->assertStringContainsString('B "Revenue": 120 values · numeric · min 10 · max 1,200 · sum 72,600 · mean 605', $markdown);
        $this->assertStringContainsString('C "Note": 1 values · 1 distinct text value · value: only one note', $markdown);
        // The capped table still follows the profile.
        $this->assertStringContainsString('| 1 | Region | Revenue | Note |', $markdown);
        $this->assertStringContainsString('111 more rows', $markdown);
        $this->assertLessThan(strpos($markdown, '| 1 | Region'), strpos($markdown, '### Profile'));
    }

    public function testSmallSheetHasNoProfileBlock(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'x');
        $sheet->setCellValue('A2', 1);
        $path = $this->tmpDir.'/small.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $markdown = (new StructuredTextExtractor(new NullLogger(), 500))->extract($path, 'xlsx');

        $this->assertNotNull($markdown);
        $this->assertStringNotContainsString('### Profile', $markdown);
    }

    public function testDeckKeepsSlideTitlesAndNotes(): void
    {
        $presentation = new PhpPresentation();
        $slide = $presentation->getActiveSlide();
        $shape = $slide->createRichTextShape();
        $shape->createTextRun('Cover');
        $body = $slide->createRichTextShape();
        $body->createTextRun('Welcome');
        $noteShape = $slide->getNote()->createRichTextShape();
        $noteShape->createTextRun('Say hello');
        $color = new Color(Color::COLOR_BLACK);
        $noteShape->getActiveParagraph()->getFont()->setColor($color);

        $second = $presentation->createSlide();
        $title = $second->createRichTextShape();
        $title->createTextRun('Agenda');
        $second->createRichTextShape()->createTextRun('Item one');

        $path = $this->tmpDir.'/deck.pptx';
        \PhpOffice\PhpPresentation\IOFactory::createWriter($presentation, 'PowerPoint2007')->save($path);

        $markdown = (new StructuredTextExtractor(new NullLogger()))->extract($path, 'pptx');

        $this->assertNotNull($markdown);
        $this->assertStringContainsString('## Slide 1 — Cover', $markdown);
        $this->assertStringContainsString('Welcome', $markdown);
        $this->assertStringContainsString('_Notes:_ Say hello', $markdown);
        $this->assertStringContainsString('## Slide 2 — Agenda', $markdown);
    }
}
