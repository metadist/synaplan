<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\AI\Service\AiFacade;
use App\Service\File\FileProcessor;
use App\Service\File\HeicConverter;
use App\Service\File\PdfRasterizer;
use App\Service\File\TextCleaner;
use App\Service\File\TikaClient;
use App\Service\File\VideoAnalysisService;
use App\Service\WhisperService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Store-only uploads (.jar) may be kept and attached, but Tika must never
 * unpack them — a JAR is a ZIP and Tika would extract class files and assets.
 */
final class FileProcessorStoreOnlyTest extends TestCase
{
    private TikaClient&MockObject $tikaClient;
    private FileProcessor $processor;

    protected function setUp(): void
    {
        $this->tikaClient = $this->createMock(TikaClient::class);

        $this->processor = new FileProcessor(
            $this->tikaClient,
            $this->createStub(PdfRasterizer::class),
            new TextCleaner(),
            $this->createStub(AiFacade::class),
            $this->createStub(WhisperService::class),
            $this->createStub(VideoAnalysisService::class),
            new HeicConverter(new NullLogger()),
            new NullLogger(),
            sys_get_temp_dir(),
            100,
            0.5,
            '/nonexistent/ffmpeg',
        );
    }

    public function testJarExtractionIsSkippedAndNeverSentToTika(): void
    {
        $this->tikaClient->expects(self::never())->method('extractText');

        [$text, $meta] = $this->processor->extractText('does-not-need-to-exist.jar', 'jar', 1);

        self::assertSame('', $text);
        self::assertSame('skipped_store_only', $meta['strategy']);
        self::assertSame('jar', $meta['ext']);
    }

    public function testJarExtensionIsCaseInsensitive(): void
    {
        $this->tikaClient->expects(self::never())->method('extractText');

        [, $meta] = $this->processor->extractText('Mod.JAR', 'JAR', 1);

        self::assertSame('skipped_store_only', $meta['strategy']);
    }
}
