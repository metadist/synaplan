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
