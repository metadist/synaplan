<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\DocumentGeneratorService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class DocumentGeneratorServiceTest extends TestCase
{
    private DocumentGeneratorService $service;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->service = new DocumentGeneratorService(new NullLogger());
        $this->tmpDir = sys_get_temp_dir().'/docgen_'.uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    public function testIsBinaryFormat(): void
    {
        $this->assertTrue($this->service->isBinaryFormat('docx'));
        $this->assertTrue($this->service->isBinaryFormat('XLSX'));
        $this->assertTrue($this->service->isBinaryFormat('pptx'));
        $this->assertFalse($this->service->isBinaryFormat('txt'));
        $this->assertFalse($this->service->isBinaryFormat('csv'));
        $this->assertFalse($this->service->isBinaryFormat('md'));
    }

    public function testDocxIsValidOoxmlZip(): void
    {
        $path = $this->tmpDir.'/test.docx';
        $this->service->write("# Title\n\nSome **bold** text and a paragraph.", 'docx', $path);

        $this->assertFileExists($path);
        $this->assertTrue($this->isZipContaining($path, 'word/document.xml'));
        $this->assertGreaterThan(1000, filesize($path), 'A real DOCX is far larger than its text source');
    }

    public function testDocxFallbackForUnparsableContentStillProducesValidFile(): void
    {
        $path = $this->tmpDir.'/plain.docx';
        $this->service->write("Just a plain line\nAnother line", 'docx', $path);

        $this->assertTrue($this->isZipContaining($path, 'word/document.xml'));
    }

    /**
     * Issue #1196: LLMs emit bare `<br>` inside markdown table cells. PhpWord's
     * XML parser rejects the unclosed tag and silently produced a structurally
     * valid but EMPTY DOCX (no <w:t> runs). The generator must now self-close
     * void tags so the table content survives.
     */
    public function testDocxWithBrInTableCellContainsText(): void
    {
        $path = $this->tmpDir.'/table_br.docx';
        $content = "| Day | Exercise |\n| --- | --- |\n| 1 | Bench press<br>Pull-ups |";
        $this->service->write($content, 'docx', $path);

        $documentXml = $this->readDocxDocument($path);
        $this->assertStringContainsString('<w:t', $documentXml, 'DOCX with <br> in a table cell must contain text runs');
        $this->assertStringContainsString('Bench press', $documentXml);
        $this->assertStringContainsString('Pull-ups', $documentXml);
    }

    /**
     * Issue #1196 (defense in depth): even pathological HTML must never yield a
     * blank-but-valid DOCX — the post-write assertion rebuilds from plain text.
     */
    public function testDocxAlwaysContainsTextForNonEmptyContent(): void
    {
        $path = $this->tmpDir.'/pathological.docx';
        $content = "Line one<br>Line two\n\n| a | b |\n| --- | --- |\n| x<br>y | z |";
        $this->service->write($content, 'docx', $path);

        $documentXml = $this->readDocxDocument($path);
        $this->assertStringContainsString('<w:t', $documentXml, 'A non-empty source must always yield a non-empty DOCX body');
    }

    public function testWriteDocxThrowsOnEmptyContent(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->write("   \n  ", 'docx', $this->tmpDir.'/empty.docx');
    }

    public function testXlsxIsValidOoxmlZip(): void
    {
        $path = $this->tmpDir.'/test.xlsx';
        $this->service->write("Name,Age\nJohn,25\nJane,30", 'xlsx', $path);

        $this->assertFileExists($path);
        $this->assertTrue($this->isZipContaining($path, 'xl/workbook.xml'));
    }

    public function testPptxIsValidOoxmlZip(): void
    {
        $path = $this->tmpDir.'/test.pptx';
        $this->service->write("# Slide One\nContent\n\n# Slide Two\nMore content", 'pptx', $path);

        $this->assertFileExists($path);
        $this->assertTrue($this->isZipContaining($path, '[Content_Types].xml'));
    }

    public function testTextFormatsAreWrittenVerbatim(): void
    {
        $path = $this->tmpDir.'/test.csv';
        $content = "a,b,c\n1,2,3";
        $this->service->write($content, 'csv', $path);

        $this->assertSame($content, file_get_contents($path));
    }

    public function testUnknownExtensionIsWrittenAsText(): void
    {
        $path = $this->tmpDir.'/test.md';
        $content = "# Heading\n\ntext";
        $this->service->write($content, 'md', $path);

        $this->assertSame($content, file_get_contents($path));
    }

    private function readDocxDocument(string $path): string
    {
        $zip = new \ZipArchive();
        $this->assertTrue(true === $zip->open($path), 'DOCX must be a valid OOXML zip');
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertNotFalse($xml, 'DOCX must contain word/document.xml');

        return (string) $xml;
    }

    private function isZipContaining(string $path, string $entry): bool
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            return false;
        }
        $found = false !== $zip->locateName($entry);
        $zip->close();

        return $found;
    }
}
