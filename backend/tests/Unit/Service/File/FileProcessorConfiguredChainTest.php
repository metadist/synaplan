<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\AI\Service\AiFacade;
use App\Plug\Extraction\ContentExtractorInterface;
use App\Plug\Extraction\Docling\DoclingRejectedException;
use App\Plug\Extraction\Docling\DoclingUnavailableException;
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
 * Issue #1792 / #1793 — the saved chain is the execution order, and attempts
 * are recorded for every built-in step.
 */
final class FileProcessorConfiguredChainTest extends TestCase
{
    public function testDocumentChainHonoursSingleAdapterAndSkipsTika(): void
    {
        $dir = sys_get_temp_dir();
        $relative = 'chain-pdf-'.uniqid('', true).'.pdf';
        copy(dirname(__DIR__, 3).'/Fixtures/extraction/files/tiny.pdf', $dir.'/'.$relative);

        $tika = $this->createMock(TikaClient::class);
        $tika->expects($this->never())->method('extractText');
        $tika->method('isEnabled')->willReturn(true);

        $png = $dir.'/page-1.png';
        file_put_contents($png, 'png');
        $rasterizer = $this->createMock(PdfRasterizer::class);
        $rasterizer->method('pdfToPng')->willReturn([$png]);
        $rasterizer->method('getLastEngine')->willReturn('pdftoppm');

        $ai = $this->createMock(AiFacade::class);
        $ai->expects($this->once())->method('analyzeImage')->willReturn([
            'content' => 'Vision read this scanned page',
            'provider' => 'groq',
        ]);

        $processor = $this->processor(
            $dir,
            ['pdf_vision'],
            tika: $tika,
            rasterizer: $rasterizer,
            ai: $ai,
        );

        [$text, $meta] = $processor->extractText($relative, 'pdf', 7);

        self::assertSame('Vision read this scanned page', $text);
        self::assertSame('rasterize_vision', $meta['strategy'] ?? null);
        self::assertSame('pdf_vision', $meta['attempts'][0]['key'] ?? null);
        @unlink($dir.'/'.$relative);
        @unlink($png);
    }

    public function testTikaLowQualityIsRecordedThenVisionWins(): void
    {
        $dir = sys_get_temp_dir();
        $relative = 'chain-lowq-'.uniqid('', true).'.pdf';
        copy(dirname(__DIR__, 3).'/Fixtures/extraction/files/tiny.pdf', $dir.'/'.$relative);

        $tika = $this->createMock(TikaClient::class);
        $tika->method('isEnabled')->willReturn(true);
        $tika->method('extractText')->willReturn(['aaaaaaa', []]);

        $png = $dir.'/page-lowq.png';
        file_put_contents($png, 'png');
        $rasterizer = $this->createMock(PdfRasterizer::class);
        $rasterizer->method('pdfToPng')->willReturn([$png]);
        $rasterizer->method('getLastEngine')->willReturn('pdftoppm');

        $ai = $this->createMock(AiFacade::class);
        $ai->method('analyzeImage')->willReturn([
            'content' => 'Readable vision text from the scan',
            'provider' => 'groq',
        ]);

        $plugConfig = $this->createMock(PlugConfigService::class);
        $plugConfig->method('extractionChain')->willReturn(['tika', 'pdf_vision']);
        $plugConfig->method('qualityApplyTo')->willReturn(['pdf']);
        $plugConfig->method('qualityMinLength')->willReturn(10);
        $plugConfig->method('qualityMinEntropy')->willReturn(3.0);

        $processor = $this->processor(
            $dir,
            ['tika', 'pdf_vision'],
            tika: $tika,
            rasterizer: $rasterizer,
            ai: $ai,
            plugConfig: $plugConfig,
            qualityGate: new ExtractionQualityGate($plugConfig, new TextCleaner()),
        );

        [$text, $meta] = $processor->extractText($relative, 'pdf', 1);

        self::assertSame('Readable vision text from the scan', $text);
        self::assertSame('rasterize_vision', $meta['strategy'] ?? null);
        $keys = array_column($meta['attempts'] ?? [], 'key');
        self::assertSame(['tika', 'pdf_vision'], $keys);
        self::assertSame('low_quality', $meta['attempts'][0]['verdict'] ?? null);
        self::assertSame('quality_ok', $meta['attempts'][1]['verdict'] ?? null);
        @unlink($dir.'/'.$relative);
        @unlink($png);
    }

    public function testAudioChainPrefersLocalWhisperWhenListedFirst(): void
    {
        $dir = sys_get_temp_dir();
        $relative = 'chain-audio-'.uniqid('', true).'.mp3';
        file_put_contents($dir.'/'.$relative, 'fake-mp3');

        $ai = $this->createMock(AiFacade::class);
        $ai->method('hasConfiguredSttProvider')->willReturn(true);
        $ai->expects($this->never())->method('transcribe');

        $whisper = $this->createMock(WhisperService::class);
        $whisper->method('isAvailable')->willReturn(true);
        $whisper->expects($this->once())->method('transcribe')->willReturn([
            'text' => 'hello from local whisper',
            'language' => 'en',
            'duration' => 1.0,
            'model' => 'base',
        ]);

        $processor = $this->processor(
            $dir,
            ['whisper_local', 'stt_cloud'],
            ai: $ai,
            whisper: $whisper,
        );

        [$text, $meta] = $processor->extractText($relative, 'mp3', 4);

        self::assertSame('hello from local whisper', $text);
        self::assertSame('whisper_local', $meta['strategy'] ?? null);
        @unlink($dir.'/'.$relative);
    }

    public function testDoclingRejectedIsRecordedThenTikaWins(): void
    {
        $dir = sys_get_temp_dir();
        $relative = 'chain-rej-'.uniqid('', true).'.pdf';
        copy(dirname(__DIR__, 3).'/Fixtures/extraction/files/tiny.pdf', $dir.'/'.$relative);

        $extra = $this->throwingDocling(new DoclingRejectedException('Docling convert returned HTTP 422'));
        $tika = $this->createMock(TikaClient::class);
        $tika->method('isEnabled')->willReturn(true);
        $tika->method('extractText')->willReturn([
            'The quarterly revenue for EMEA was 4.2 million EUR in Q3 2025 according to finance.',
            [],
        ]);

        $processor = $this->processor(
            $dir,
            ['docling', 'tika'],
            tika: $tika,
            extra: $extra,
        );

        [$text, $meta] = $processor->extractText($relative, 'pdf', 1);

        self::assertStringContainsString('EMEA', $text);
        self::assertSame('tika', $meta['strategy'] ?? null);
        self::assertSame('rejected', $meta['attempts'][0]['verdict'] ?? null);
        self::assertSame('docling', $meta['attempts'][0]['key'] ?? null);
        @unlink($dir.'/'.$relative);
    }

    public function testDoclingUnavailableIsRecordedThenTikaWins(): void
    {
        $dir = sys_get_temp_dir();
        $relative = 'chain-unav-'.uniqid('', true).'.pdf';
        copy(dirname(__DIR__, 3).'/Fixtures/extraction/files/tiny.pdf', $dir.'/'.$relative);

        $extra = $this->throwingDocling(new DoclingUnavailableException('Docling unavailable — connection refused'));
        $tika = $this->createMock(TikaClient::class);
        $tika->method('isEnabled')->willReturn(true);
        $tika->method('extractText')->willReturn([
            'The quarterly revenue for EMEA was 4.2 million EUR in Q3 2025 according to finance.',
            [],
        ]);

        $processor = $this->processor(
            $dir,
            ['docling', 'tika'],
            tika: $tika,
            extra: $extra,
        );

        [, $meta] = $processor->extractText($relative, 'pdf', 1);

        self::assertSame('unavailable', $meta['attempts'][0]['verdict'] ?? null);
        self::assertSame('tika', $meta['strategy'] ?? null);
        @unlink($dir.'/'.$relative);
    }

    public function testDoclingOnlyDoesNotFallThroughToBuiltins(): void
    {
        $dir = sys_get_temp_dir();
        $relative = 'chain-only-'.uniqid('', true).'.md';
        file_put_contents($dir.'/'.$relative, "Native would have won\n");

        $extra = $this->throwingDocling(new \RuntimeException('sidecar down'));
        $processor = $this->processor($dir, ['docling'], extra: $extra);

        [$text, $meta] = $processor->extractText($relative, 'md');

        self::assertSame('', $text);
        self::assertSame('chain_exhausted', $meta['strategy'] ?? null);
        @unlink($dir.'/'.$relative);
    }

    /**
     * @param list<string> $chain
     */
    private function processor(
        string $uploadDir,
        array $chain,
        ?TikaClient $tika = null,
        ?PdfRasterizer $rasterizer = null,
        ?AiFacade $ai = null,
        ?WhisperService $whisper = null,
        ?ContentExtractorInterface $extra = null,
        ?PlugConfigService $plugConfig = null,
        ?ExtractionQualityGate $qualityGate = null,
    ): FileProcessor {
        $plugConfig ??= $this->plugConfig($chain);
        $extractors = null !== $extra ? [$extra] : [];

        return new FileProcessor(
            $tika ?? $this->createStub(TikaClient::class),
            $rasterizer ?? $this->createStub(PdfRasterizer::class),
            new TextCleaner(),
            $ai ?? $this->createStub(AiFacade::class),
            $whisper ?? $this->createStub(WhisperService::class),
            $this->createStub(VideoAnalysisService::class),
            new HeicConverter(new NullLogger()),
            new NullLogger(),
            $uploadDir,
            10,
            3.0,
            '/nonexistent/ffmpeg',
            null,
            null,
            new ExtractionRegistry($extractors, $plugConfig, new NullLogger()),
            $plugConfig,
            $qualityGate,
        );
    }

    /**
     * @param list<string> $chain
     */
    private function plugConfig(array $chain): PlugConfigService
    {
        $plugConfig = $this->createMock(PlugConfigService::class);
        $plugConfig->method('extractionChain')->willReturn($chain);

        return $plugConfig;
    }

    private function throwingDocling(\Throwable $error): ContentExtractorInterface
    {
        return new class($error) implements ContentExtractorInterface {
            public function __construct(private \Throwable $error)
            {
            }

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
                throw $this->error;
            }

            public function health(): PlugHealth
            {
                return PlugHealth::available();
            }
        };
    }
}
