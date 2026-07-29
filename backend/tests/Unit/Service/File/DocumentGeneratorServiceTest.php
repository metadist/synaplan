<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\DocumentGeneratorService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
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

    /**
     * Issue #1228: an image marker must become an embedded OOXML image at the
     * requested position instead of being ignored or rendered as marker text.
     */
    public function testDocxEmbedsReferencedImage(): void
    {
        $imagePath = $this->tmpDir.'/profile.png';
        file_put_contents($imagePath, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
        ));

        $path = $this->tmpDir.'/with_image.docx';
        $this->service->write(
            "# Application\n\n{{IMAGE:file:42}}\n\nCover letter",
            'docx',
            $path,
            ['file:42' => $imagePath],
        );

        $documentXml = $this->readDocxDocument($path);
        $this->assertTrue(
            str_contains($documentXml, '<w:drawing>') || str_contains($documentXml, '<w:pict>'),
            'DOCX must contain an OOXML image element',
        );
        $this->assertStringNotContainsString('{{IMAGE:', $documentXml);
        $this->assertTrue($this->docxContainsMedia($path), 'DOCX must contain the embedded image binary');
    }

    public function testDocxSkipsUnconvertibleWebpImageAndCleansTemporaryFiles(): void
    {
        if (!class_exists(\Imagick::class) && !function_exists('imagewebp')) {
            $this->markTestSkipped('WebP generation requires Imagick or GD');
        }

        $validWebp = $this->tmpDir.'/valid.webp';
        if (class_exists(\Imagick::class)) {
            $image = new \Imagick();
            $image->newImage(1, 1, new \ImagickPixel('white'));
            $image->setImageFormat('webp');
            $image->writeImage($validWebp);
            $image->clear();
            $image->destroy();
        } else {
            $image = imagecreatetruecolor(1, 1);
            $this->assertNotFalse($image);
            $this->assertTrue(imagewebp($image, $validWebp));
            imagedestroy($image);
        }

        $invalidWebp = $this->tmpDir.'/invalid.webp';
        file_put_contents($invalidWebp, 'not a webp image');
        $temporaryFilesBefore = glob(sys_get_temp_dir().'/docx_image_*') ?: [];

        $path = $this->tmpDir.'/conversion_failure.docx';
        $this->service->write(
            "Cover letter\n\n{{IMAGE:file:1}}{{IMAGE:file:2}}",
            'docx',
            $path,
            ['file:1' => $validWebp, 'file:2' => $invalidWebp],
        );

        $this->assertFileExists($path, 'A broken image must not discard the document');
        $this->assertStringContainsString('Cover letter', $this->readDocxDocument($path));

        $leakedFiles = array_values(array_diff(
            glob(sys_get_temp_dir().'/docx_image_*') ?: [],
            $temporaryFilesBefore,
        ));
        foreach ($leakedFiles as $leakedFile) {
            @unlink($leakedFile);
        }

        $this->assertSame([], $leakedFiles, 'Converted WebP temporary files must be removed after the write');
    }

    /**
     * Issue #1228 follow-up: the model emits `{{IMAGE:attached:1}}` even when
     * nothing is attached. An unresolvable marker must cost the image, never
     * the whole document.
     */
    public function testDocxSkipsUnresolvedImageReference(): void
    {
        $path = $this->tmpDir.'/missing_image.docx';
        $this->service->write("Dear Sir or Madam\n\n{{IMAGE:file:999}}\n\nKind regards", 'docx', $path);

        $documentXml = $this->readDocxDocument($path);
        $this->assertStringContainsString('Dear Sir or Madam', $documentXml);
        $this->assertStringContainsString('Kind regards', $documentXml);
        $this->assertStringNotContainsString('{{IMAGE:', $documentXml);
        $this->assertFalse($this->docxContainsMedia($path));
    }

    /**
     * The plain-text fallback exists so a document is always produced — it must
     * not re-throw on the very image marker that can send it there. Content
     * consisting only of an unresolvable marker leaves an empty body and
     * therefore always takes the rebuild path.
     */
    public function testDocxPlainTextFallbackSurvivesUnresolvedImageReference(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        $service = new DocumentGeneratorService($logger);

        $path = $this->tmpDir.'/fallback_image.docx';
        $service->write('{{IMAGE:file:999}}', 'docx', $path);

        $this->assertContains(
            'DocumentGeneratorService: DOCX produced no content, rebuilding with plain text fallback',
            $logger->messages,
        );
        $this->assertTrue($this->isZipContaining($path, 'word/document.xml'));
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

    /**
     * Only DOCX can embed images — a text format would show the raw marker.
     */
    public function testTextFormatsDropImageMarkers(): void
    {
        $path = $this->tmpDir.'/markers.md';
        $this->service->write("# Heading\n\n{{IMAGE:file:42}}\n\ntext", 'md', $path);

        $this->assertSame("# Heading\n\ntext", file_get_contents($path));
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

    private function docxContainsMedia(string $path): bool
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path)) {
            return false;
        }

        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $name = $zip->getNameIndex($index);
            if (is_string($name) && str_starts_with($name, 'word/media/')) {
                $zip->close();

                return true;
            }
        }

        $zip->close();

        return false;
    }
}
