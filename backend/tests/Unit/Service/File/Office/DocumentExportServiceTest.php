<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File\Office;

use App\Entity\File;
use App\Service\File\DocumentGeneratorService;
use App\Service\File\DocumentImageReferenceResolver;
use App\Service\File\Office\DocumentExportService;
use App\Service\File\Office\OfficeConverterClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DocumentExportServiceTest extends TestCase
{
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/office-export-'.bin2hex(random_bytes(4));
        mkdir($this->uploadDir.'/u', 0755, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->uploadDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($this->uploadDir);
    }

    public function testPdfSourceIsReturnedAsIs(): void
    {
        $path = $this->uploadDir.'/u/report.pdf';
        file_put_contents($path, '%PDF-1.4');

        $service = $this->service($this->disabledConverter());
        $out = $service->exportToPdf($this->file('u/report.pdf', 'report.pdf', 'pdf'));

        self::assertSame($path, $out);
    }

    public function testOfficeReturnsNullWhenEngineOff(): void
    {
        file_put_contents($this->uploadDir.'/u/brief.docx', 'PK');
        $converter = $this->createMock(OfficeConverterClient::class);
        $converter->method('isEnabled')->willReturn(false);
        $converter->expects(self::never())->method('convert');

        $out = $this->service($converter)->exportToPdf($this->file('u/brief.docx', 'brief.docx', 'docx'));

        self::assertNull($out);
    }

    public function testOfficeConvertsAndCaches(): void
    {
        $source = $this->uploadDir.'/u/brief.docx';
        file_put_contents($source, 'PK');
        $converted = $this->uploadDir.'/u/brief.convert-tmp.pdf';
        file_put_contents($converted, '%PDF-ok');

        $converter = $this->createMock(OfficeConverterClient::class);
        $converter->method('isEnabled')->willReturn(true);
        $converter->expects(self::once())->method('convert')->willReturn($converted);

        $service = $this->service($converter);
        $file = $this->file('u/brief.docx', 'brief.docx', 'docx');
        $first = $service->exportToPdf($file);
        $second = $service->exportToPdf($file);

        self::assertSame($this->uploadDir.'/u/brief.export.pdf', $first);
        self::assertSame($first, $second);
        self::assertSame('%PDF-ok', file_get_contents((string) $first));
    }

    public function testWordDocumentIsConvertedWithoutSpreadsheetOptions(): void
    {
        file_put_contents($this->uploadDir.'/u/brief.docx', 'PK');
        $converted = $this->uploadDir.'/u/brief.convert-tmp.pdf';
        file_put_contents($converted, '%PDF-ok');

        $converter = $this->createMock(OfficeConverterClient::class);
        $converter->method('isEnabled')->willReturn(true);
        $converter->expects(self::once())->method('convert')
            ->with(self::anything(), 'pdf', [])
            ->willReturn($converted);

        self::assertNotNull($this->service($converter)->exportToPdf($this->file('u/brief.docx', 'brief.docx', 'docx')));
    }

    /**
     * #1690: the Calc print layout clips long row labels; a workbook is
     * exported with Collabora's full-sheet preview and cached under its own
     * name so an earlier paginated export is not served again.
     */
    public function testSpreadsheetIsConvertedAsFullSheetPreviewAndCachedSeparately(): void
    {
        file_put_contents($this->uploadDir.'/u/forecast.xlsx', 'PK');
        $stale = $this->uploadDir.'/u/forecast.export.pdf';
        file_put_contents($stale, '%PDF-clipped');
        touch($stale, time() + 60);
        $converted = $this->uploadDir.'/u/forecast.convert-tmp.pdf';
        file_put_contents($converted, '%PDF-full-sheet');

        $converter = $this->createMock(OfficeConverterClient::class);
        $converter->method('isEnabled')->willReturn(true);
        $converter->expects(self::once())->method('convert')
            ->with(self::anything(), 'pdf', [OfficeConverterClient::OPTION_FULL_SHEET_PREVIEW => true])
            ->willReturn($converted);

        $out = $this->service($converter)->exportToPdf($this->file('u/forecast.xlsx', 'forecast.xlsx', 'xlsx'));

        self::assertSame($this->uploadDir.'/u/forecast.export.sheet.pdf', $out);
        self::assertSame('%PDF-full-sheet', file_get_contents((string) $out));
    }

    public function testCachedPathsCoverCurrentAndLegacyNames(): void
    {
        self::assertSame('u/brief.export.pdf', DocumentExportService::cachedRelativePath('u/brief.docx'));
        self::assertSame(['u/brief.export.pdf'], DocumentExportService::cachedRelativePaths('u/brief.docx'));

        self::assertSame('u/forecast.export.sheet.pdf', DocumentExportService::cachedRelativePath('u/forecast.xlsx'));
        self::assertSame(
            ['u/forecast.export.sheet.pdf', 'u/forecast.export.pdf'],
            DocumentExportService::cachedRelativePaths('u/forecast.xlsx'),
        );
        self::assertSame(['u/data.export.sheet.pdf', 'u/data.export.pdf'], DocumentExportService::cachedRelativePaths('u/data.ods'));
    }

    public function testDeleteCachedPdfRemovesEveryCacheName(): void
    {
        $current = $this->uploadDir.'/u/forecast.export.sheet.pdf';
        $legacy = $this->uploadDir.'/u/forecast.export.pdf';
        file_put_contents($current, '%PDF');
        file_put_contents($legacy, '%PDF');

        $this->service($this->disabledConverter())->deleteCachedPdf('u/forecast.xlsx');

        self::assertFileDoesNotExist($current);
        self::assertFileDoesNotExist($legacy);
    }

    public function testUnsupportedTypeReturnsNull(): void
    {
        file_put_contents($this->uploadDir.'/u/song.mp3', 'xx');
        self::assertNull($this->service($this->disabledConverter())->exportToPdf(
            $this->file('u/song.mp3', 'song.mp3', 'mp3')
        ));
    }

    private function service(OfficeConverterClient $converter): DocumentExportService
    {
        return new DocumentExportService(
            $converter,
            $this->createMock(DocumentGeneratorService::class),
            $this->createMock(DocumentImageReferenceResolver::class),
            new NullLogger(),
            $this->uploadDir,
        );
    }

    private function disabledConverter(): OfficeConverterClient
    {
        $converter = $this->createMock(OfficeConverterClient::class);
        $converter->method('isEnabled')->willReturn(false);

        return $converter;
    }

    private function file(string $path, string $name, string $type): File
    {
        $file = new File();
        $file->setUserId(1);
        $file->setFilePath($path);
        $file->setFileName($name);
        $file->setFileType($type);
        $file->setFileSize(10);
        $file->setFileMime('application/octet-stream');

        return $file;
    }
}
