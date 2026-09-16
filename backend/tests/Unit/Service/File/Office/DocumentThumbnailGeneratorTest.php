<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File\Office;

use App\Entity\File;
use App\Service\File\Office\DocumentThumbnailGenerator;
use App\Service\File\Office\OfficeConverterClient;
use App\Service\File\PdfRasterizer;
use App\Service\File\ThumbnailService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DocumentThumbnailGeneratorTest extends TestCase
{
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/office-thumbs-'.bin2hex(random_bytes(4));
        mkdir($this->uploadDir.'/u', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->uploadDir);
    }

    public function testOfficeConvertDisabledReturnsNull(): void
    {
        $converter = $this->createMock(OfficeConverterClient::class);
        $converter->method('isEnabled')->willReturn(false);
        $converter->expects(self::never())->method('convert');

        $generator = $this->generator($converter, $this->createMock(PdfRasterizer::class));
        $this->writeSource('u/brief.docx', 'PK');

        self::assertNull($generator->generate($this->file('u/brief.docx', 'brief.docx', 'docx')));
    }

    public function testOfficeConvertWritesPoster(): void
    {
        if (!class_exists(\Imagick::class)) {
            self::markTestSkipped('Imagick is required to write the JPEG poster');
        }

        $png = $this->tinyPng();
        $converter = $this->createMock(OfficeConverterClient::class);
        $converter->method('isEnabled')->willReturn(true);
        $converter->method('convert')->willReturn($png);

        $generator = $this->generator($converter, $this->createMock(PdfRasterizer::class));
        $this->writeSource('u/brief.docx', 'PK');

        $thumb = $generator->generate($this->file('u/brief.docx', 'brief.docx', 'docx'));

        self::assertSame('u/brief_thumb.jpg', $thumb);
        self::assertFileExists($this->uploadDir.'/u/brief_thumb.jpg');
        self::assertFileDoesNotExist($png);
    }

    public function testPdfUsesRasterizerPageOne(): void
    {
        if (!class_exists(\Imagick::class)) {
            self::markTestSkipped('Imagick is required to write the JPEG poster');
        }

        $png = $this->tinyPng();
        $rasterizer = $this->createMock(PdfRasterizer::class);
        $rasterizer->expects(self::once())
            ->method('pdfToPng')
            ->with(self::stringContains('report.pdf'), 1)
            ->willReturn([$png]);

        $converter = $this->createMock(OfficeConverterClient::class);
        $converter->expects(self::never())->method('convert');

        $generator = $this->generator($converter, $rasterizer);
        $this->writeSource('u/report.pdf', '%PDF');

        $thumb = $generator->generate($this->file('u/report.pdf', 'report.pdf', 'pdf'));

        self::assertSame('u/report_thumb.jpg', $thumb);
        self::assertFileExists($this->uploadDir.'/u/report_thumb.jpg');
    }

    public function testUnsupportedExtensionReturnsNull(): void
    {
        $generator = $this->generator(
            $this->createMock(OfficeConverterClient::class),
            $this->createMock(PdfRasterizer::class),
        );
        $this->writeSource('u/song.mp3', 'xx');

        self::assertNull($generator->generate($this->file('u/song.mp3', 'song.mp3', 'mp3')));
    }

    private function generator(OfficeConverterClient $converter, PdfRasterizer $rasterizer): DocumentThumbnailGenerator
    {
        return new DocumentThumbnailGenerator(
            $converter,
            $rasterizer,
            new ThumbnailService($this->uploadDir, new NullLogger()),
            new NullLogger(),
            $this->uploadDir,
        );
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

    private function writeSource(string $relative, string $bytes): void
    {
        $absolute = $this->uploadDir.'/'.$relative;
        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0755, true);
        }
        file_put_contents($absolute, $bytes);
    }

    private function tinyPng(): string
    {
        $path = $this->uploadDir.'/u/page-'.bin2hex(random_bytes(3)).'.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        self::assertNotFalse($png);
        file_put_contents($path, $png);

        return $path;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
