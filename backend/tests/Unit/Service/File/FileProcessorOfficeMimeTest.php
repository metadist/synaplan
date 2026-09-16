<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\AI\Service\AiFacade;
use App\Service\File\FileProcessor;
use App\Service\File\HeicConverter;
use App\Service\File\Office\OfficeConverterClient;
use App\Service\File\PdfRasterizer;
use App\Service\File\TextCleaner;
use App\Service\File\TikaClient;
use App\Service\File\VideoAnalysisService;
use App\Service\WhisperService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Issue #1907: office/calendar uploads that the filesystem reports as zip or
 * octet-stream must still reach Tika (or native conversion) with the canonical
 * MIME, not as a generic archive.
 */
final class FileProcessorOfficeMimeTest extends TestCase
{
    /**
     * Formats that are extracted by Tika as-is (no Collabora rewrite).
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function tikaDirectFormatsProvider(): iterable
    {
        yield 'opendocument graphics' => ['odg', 'application/vnd.oasis.opendocument.graphics'];
        yield 'opendocument formula' => ['odf', 'application/vnd.oasis.opendocument.formula'];
    }

    /**
     * Formats rewritten to OOXML before Tika (native Collabora convert).
     *
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function nativeConvertFormatsProvider(): iterable
    {
        yield 'opendocument spreadsheet' => ['ods', 'xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        yield 'opendocument presentation' => ['odp', 'pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'];
        yield 'apple numbers' => ['numbers', 'xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        yield 'apple keynote' => ['key', 'pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'];
    }

    #[DataProvider('tikaDirectFormatsProvider')]
    public function testTikaReceivesCanonicalMimeWhenFilesystemReportsZip(string $ext, string $expectedMime): void
    {
        $dir = sys_get_temp_dir();
        $relative = 'office-mime-'.uniqid('', true).'.'.$ext;
        $absolute = $dir.'/'.$relative;
        // ZIP magic so mime_content_type() is application/zip (or octet-stream).
        file_put_contents($absolute, "PK\x03\x04");

        try {
            $tika = $this->enabledTika();
            $tika->expects(self::once())
                ->method('extractText')
                ->with($absolute, $expectedMime)
                ->willReturn(['extracted body', []]);

            $processor = $this->processor($dir, $tika);
            [$text, $meta] = $processor->extractText($relative, $ext, 1);

            self::assertSame('extracted body', $text);
            self::assertSame('tika', $meta['strategy'] ?? null);
            self::assertSame($expectedMime, $meta['mime'] ?? null);
            self::assertSame($ext, $meta['ext'] ?? null);
        } finally {
            @unlink($absolute);
        }
    }

    #[DataProvider('nativeConvertFormatsProvider')]
    public function testLegacyOfficeFormatsConvertThenTikaSeesTargetMime(
        string $ext,
        string $targetExt,
        string $convertedMime,
    ): void {
        $dir = sys_get_temp_dir();
        $relative = 'office-convert-'.uniqid('', true).'.'.$ext;
        $absolute = $dir.'/'.$relative;
        file_put_contents($absolute, "PK\x03\x04");

        $converted = $dir.'/converted-'.uniqid('', true).'.'.$targetExt;
        file_put_contents($converted, "PK\x03\x04converted");

        try {
            $office = $this->createMock(OfficeConverterClient::class);
            $office->method('isEnabled')->willReturn(true);
            $office->expects(self::once())
                ->method('convert')
                ->with($absolute, $targetExt)
                ->willReturn($converted);

            $tika = $this->enabledTika();
            $tika->expects(self::once())
                ->method('extractText')
                ->with($converted, $convertedMime)
                ->willReturn(['converted body', []]);

            $processor = $this->processor($dir, $tika, $office);
            [$text, $meta] = $processor->extractText($relative, $ext, 1);

            self::assertSame('converted body', $text);
            self::assertSame('tika', $meta['strategy'] ?? null);
            self::assertSame($convertedMime, $meta['mime'] ?? null);
            self::assertSame($targetExt, $meta['ext'] ?? null);
            self::assertSame($ext, $meta['converted_from'] ?? null);
        } finally {
            @unlink($absolute);
            @unlink($converted);
        }
    }

    private function enabledTika(): TikaClient&MockObject
    {
        $tika = $this->createMock(TikaClient::class);
        $tika->method('isEnabled')->willReturn(true);

        return $tika;
    }

    private function processor(
        string $uploadDir,
        TikaClient $tika,
        ?OfficeConverterClient $officeConverter = null,
    ): FileProcessor {
        return new FileProcessor(
            $tika,
            $this->createStub(PdfRasterizer::class),
            new TextCleaner(),
            $this->createStub(AiFacade::class),
            $this->createStub(WhisperService::class),
            $this->createStub(VideoAnalysisService::class),
            new HeicConverter(new NullLogger()),
            new NullLogger(),
            $uploadDir,
            100,
            0.5,
            '/nonexistent/ffmpeg',
            $officeConverter,
        );
    }
}
