<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\AI\Service\AiFacade;
use App\Plug\Extraction\ContentExtractorInterface;
use App\Plug\Extraction\ExtractionQualityGate;
use App\Plug\Extraction\ExtractionRegistry;
use App\Plug\Extraction\ExtractionRequest;
use App\Plug\Extraction\ExtractionResult;
use App\Plug\PlugConfigService;
use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;
use App\Service\File\FileProcessor;
use App\Service\File\HeicConverter;
use App\Service\File\PdfRasterizer;
use App\Service\File\TextCleaner;
use App\Service\File\TikaClient;
use App\Service\File\VideoAnalysisService;
use App\Service\WhisperService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Extra (non-built-in) keys run in the configured chain order.
 */
final class FileProcessorExtraExtractorHookTest extends TestCase
{
    public function testExtraExtractorWinsBeforeBuiltInNativePath(): void
    {
        $dir = sys_get_temp_dir();
        $relative = 'plugs-extra-'.uniqid('', true).'.md';
        file_put_contents($dir.'/'.$relative, "# Built-in would read this\n");

        $extra = new class implements ContentExtractorInterface {
            public function key(): string
            {
                return 'docling';
            }

            public function descriptor(): PlugDescriptor
            {
                return new PlugDescriptor('docling', 'Docling', '', [], 'self-hosted');
            }

            public function supports(ExtractionRequest $request): bool
            {
                return '' !== $request->absolutePath;
            }

            public function extract(ExtractionRequest $request): ExtractionResult
            {
                return ExtractionResult::of('docling text from '.$request->ext, 'docling', [
                    'file' => basename($request->absolutePath),
                ]);
            }

            public function health(): PlugHealth
            {
                return PlugHealth::available();
            }
        };

        $plugConfig = $this->createMock(PlugConfigService::class);
        $plugConfig->method('extractionChain')->willReturn(['docling']);

        $processor = $this->processor(
            new ExtractionRegistry([$extra], $plugConfig, new NullLogger()),
            $plugConfig,
            $dir,
        );

        [$text, $meta] = $processor->extractText($relative, 'md');

        $this->assertSame('docling text from md', $text);
        $this->assertSame('docling', $meta['strategy'] ?? null);
        @unlink($dir.'/'.$relative);
    }

    public function testExtraExtractorFailureFallsThroughToNative(): void
    {
        $dir = sys_get_temp_dir();
        $relative = 'plugs-fallback-'.uniqid('', true).'.md';
        file_put_contents($dir.'/'.$relative, "Native fallback body\n");

        $extra = new class implements ContentExtractorInterface {
            public function key(): string
            {
                return 'docling';
            }

            public function descriptor(): PlugDescriptor
            {
                return new PlugDescriptor('docling', 'Docling', '', [], 'self-hosted');
            }

            public function supports(ExtractionRequest $request): bool
            {
                return '' !== $request->ext;
            }

            public function extract(ExtractionRequest $request): ExtractionResult
            {
                throw new \RuntimeException('sidecar down for '.$request->ext);
            }

            public function health(): PlugHealth
            {
                return PlugHealth::available();
            }
        };

        $plugConfig = $this->createMock(PlugConfigService::class);
        $plugConfig->method('extractionChain')->willReturn(['docling', 'native']);

        $processor = $this->processor(
            new ExtractionRegistry([$extra], $plugConfig, new NullLogger()),
            $plugConfig,
            $dir,
        );

        [$text, $meta] = $processor->extractText($relative, 'md');

        $this->assertSame('Native fallback body', $text);
        $this->assertSame('native_text', $meta['strategy'] ?? null);
        @unlink($dir.'/'.$relative);
    }

    public function testExtraExtractorLowQualityPdfFallsThroughWhenGateInjected(): void
    {
        $dir = sys_get_temp_dir();
        $relative = 'plugs-lowq-'.uniqid('', true).'.pdf';
        copy(dirname(__DIR__, 3).'/Fixtures/extraction/files/tiny.pdf', $dir.'/'.$relative);

        $extra = new class implements ContentExtractorInterface {
            public function key(): string
            {
                return 'docling';
            }

            public function descriptor(): PlugDescriptor
            {
                return new PlugDescriptor('docling', 'Docling', '', [], 'self-hosted');
            }

            public function supports(ExtractionRequest $request): bool
            {
                return 'pdf' === $request->ext;
            }

            public function extract(ExtractionRequest $request): ExtractionResult
            {
                return ExtractionResult::of('aaaaaaa', 'docling', ['file' => basename($request->absolutePath)]);
            }

            public function health(): PlugHealth
            {
                return PlugHealth::available();
            }
        };

        $plugConfig = $this->createMock(PlugConfigService::class);
        $plugConfig->method('extractionChain')->willReturn(['docling', 'tika']);
        $plugConfig->method('qualityApplyTo')->willReturn(['pdf']);
        $plugConfig->method('qualityMinLength')->willReturn(10);
        $plugConfig->method('qualityMinEntropy')->willReturn(3.0);

        $tika = $this->createMock(TikaClient::class);
        $tika->method('isEnabled')->willReturn(true);
        $tika->method('extractText')->willReturn([
            'The quarterly revenue for EMEA was 4.2 million EUR in Q3 2025 according to finance.',
            [],
        ]);

        $processor = new FileProcessor(
            $tika,
            $this->createStub(PdfRasterizer::class),
            new TextCleaner(),
            $this->createStub(AiFacade::class),
            $this->createStub(WhisperService::class),
            $this->createStub(VideoAnalysisService::class),
            new HeicConverter(new NullLogger()),
            new NullLogger(),
            $dir,
            10,
            3.0,
            '/nonexistent/ffmpeg',
            null,
            null,
            new ExtractionRegistry([$extra], $plugConfig, new NullLogger()),
            $plugConfig,
            new ExtractionQualityGate($plugConfig, new TextCleaner()),
        );

        [$text, $meta] = $processor->extractText($relative, 'pdf');

        $this->assertStringContainsString('EMEA', $text);
        $this->assertSame('tika', $meta['strategy'] ?? null);
        @unlink($dir.'/'.$relative);
    }

    public function testDoclingExceptionFallsThroughToTikaOnPdf(): void
    {
        $dir = sys_get_temp_dir();
        $relative = 'plugs-c7-'.uniqid('', true).'.pdf';
        copy(dirname(__DIR__, 3).'/Fixtures/extraction/files/tiny.pdf', $dir.'/'.$relative);

        $extra = new class implements ContentExtractorInterface {
            public function key(): string
            {
                return 'docling';
            }

            public function descriptor(): PlugDescriptor
            {
                return new PlugDescriptor('docling', 'Docling', '', [], 'self-hosted');
            }

            public function supports(ExtractionRequest $request): bool
            {
                return 'pdf' === $request->ext;
            }

            public function extract(ExtractionRequest $request): ExtractionResult
            {
                throw new \RuntimeException('sidecar down for '.$request->ext);
            }

            public function health(): PlugHealth
            {
                return PlugHealth::unavailable('connection refused');
            }
        };

        $plugConfig = $this->createMock(PlugConfigService::class);
        $plugConfig->method('extractionChain')->willReturn(['docling', 'tika']);

        $tika = $this->createMock(TikaClient::class);
        $tika->method('isEnabled')->willReturn(true);
        $tika->method('extractText')->willReturn([
            'The quarterly revenue for EMEA was 4.2 million EUR in Q3 2025 according to finance.',
            [],
        ]);

        $processor = $this->processor(
            new ExtractionRegistry([$extra], $plugConfig, new NullLogger()),
            $plugConfig,
            $dir,
            $tika,
        );

        [$text, $meta] = $processor->extractText($relative, 'pdf');

        $this->assertStringContainsString('EMEA', $text);
        $this->assertSame('tika', $meta['strategy'] ?? null);
        @unlink($dir.'/'.$relative);
    }

    private function processor(
        ExtractionRegistry $registry,
        PlugConfigService $plugConfig,
        string $uploadDir,
        ?TikaClient $tika = null,
    ): FileProcessor {
        return new FileProcessor(
            $tika ?? $this->createStub(TikaClient::class),
            $this->createStub(PdfRasterizer::class),
            new TextCleaner(),
            $this->createStub(AiFacade::class),
            $this->createStub(WhisperService::class),
            $this->createStub(VideoAnalysisService::class),
            new HeicConverter(new NullLogger()),
            new NullLogger(),
            $uploadDir,
            10,
            3.0,
            '/nonexistent/ffmpeg',
            null,
            null,
            $registry,
            $plugConfig,
        );
    }
}
