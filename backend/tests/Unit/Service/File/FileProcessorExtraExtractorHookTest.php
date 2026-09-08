<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\AI\Service\AiFacade;
use App\Plug\Extraction\ContentExtractorInterface;
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
 * S2 unlock: keys outside BUILTIN_EXTRACTOR_KEYS run before FileProcessor strategies.
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
        $plugConfig->method('extraExtractorKeys')->willReturn(['docling']);

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
        $plugConfig->method('extraExtractorKeys')->willReturn(['docling']);

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

    private function processor(
        ExtractionRegistry $registry,
        PlugConfigService $plugConfig,
        string $uploadDir,
    ): FileProcessor {
        return new FileProcessor(
            $this->createStub(TikaClient::class),
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
