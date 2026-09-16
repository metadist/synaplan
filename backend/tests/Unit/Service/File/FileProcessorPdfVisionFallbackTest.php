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
 * Scanned / image-only PDFs: Tika is empty, OCR often replies "no text",
 * then a describe pass must still produce searchable content.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class FileProcessorPdfVisionFallbackTest extends TestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private AiFacade&MockObject $aiFacade;
    private string $pdfRelative;
    private string $pdfPath;
    private string $pagePath;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->pdfRelative = 'file_processor_pdf_'.uniqid('', true).'.pdf';
        $this->pdfPath = sys_get_temp_dir().'/'.$this->pdfRelative;
        copy(dirname(__DIR__, 3).'/Fixtures/extraction/files/tiny.pdf', $this->pdfPath);
        $this->pagePath = sys_get_temp_dir().'/file_processor_pdf_page_'.uniqid().'.png';
        file_put_contents($this->pagePath, (string) base64_decode(self::PNG_1X1, true));
    }

    protected function tearDown(): void
    {
        @unlink($this->pdfPath);
        @unlink($this->pagePath);
    }

    public function testOcrRefusalRetriesWithDescribeAndKeepsTheDescription(): void
    {
        $tika = $this->createMock(TikaClient::class);
        $tika->method('isEnabled')->willReturn(true);
        $tika->method('extractText')->willReturn(['', []]);

        $rasterizer = $this->createMock(PdfRasterizer::class);
        $rasterizer->method('pdfToPng')->willReturn([$this->pagePath]);
        $rasterizer->method('getLastEngine')->willReturn('imagick');

        $this->aiFacade->expects(self::exactly(2))
            ->method('analyzeImage')
            ->willReturnOnConsecutiveCalls(
                [
                    'content' => 'There is no visible text in the image.',
                    'provider' => 'openai',
                ],
                [
                    'content' => 'A travel voucher. Text on page: Gutschein DT2008 200 EUR',
                    'provider' => 'openai',
                ],
            );

        $processor = $this->makeProcessor($tika, $rasterizer);
        [$text, $meta] = $processor->extractText($this->pdfRelative, 'pdf', 1);

        self::assertStringContainsString('Gutschein', $text);
        self::assertSame('rasterize_vision_describe', $meta['strategy']);
    }

    public function testProviderFailureOnEveryPageDoesNotRetryWithDescribe(): void
    {
        $tika = $this->createMock(TikaClient::class);
        $tika->method('isEnabled')->willReturn(true);
        $tika->method('extractText')->willReturn(['', []]);

        $secondPage = sys_get_temp_dir().'/file_processor_pdf_page2_'.uniqid().'.png';
        file_put_contents($secondPage, (string) base64_decode(self::PNG_1X1, true));
        $rasterizer = $this->createMock(PdfRasterizer::class);
        $rasterizer->method('pdfToPng')->willReturn([$this->pagePath, $secondPage]);

        // Two pages, provider rejects both: exactly two calls, never four.
        $this->aiFacade->expects(self::exactly(2))
            ->method('analyzeImage')
            ->willThrowException(new \RuntimeException('429 rate limited'));

        try {
            $processor = $this->makeProcessor($tika, $rasterizer);
            [$text, $meta] = $processor->extractText($this->pdfRelative, 'pdf', 1);
        } finally {
            @unlink($secondPage);
        }

        self::assertSame('', $text);
        self::assertSame('vision_failed', $meta['strategy']);
    }

    public function testVisionThinkBlocksAreStrippedFromSearchableText(): void
    {
        $tika = $this->createMock(TikaClient::class);
        $tika->method('isEnabled')->willReturn(true);
        $tika->method('extractText')->willReturn(['', []]);

        $rasterizer = $this->createMock(PdfRasterizer::class);
        $rasterizer->method('pdfToPng')->willReturn([$this->pagePath]);
        $rasterizer->method('getLastEngine')->willReturn('imagick');

        $this->aiFacade->expects(self::once())
            ->method('analyzeImage')
            ->willReturn([
                'content' => "<think>planning the OCR</think>\n\nDAS TEGERNSEE\nGUTSCHEIN IM WERT\nVON 100,- €",
                'provider' => 'groq',
            ]);

        $processor = $this->makeProcessor($tika, $rasterizer);
        [$text] = $processor->extractText($this->pdfRelative, 'pdf', 1);

        self::assertStringNotContainsString('<think>', $text);
        self::assertStringContainsString('GUTSCHEIN IM WERT', $text);
        self::assertStringContainsString('100,-', $text);
    }

    private function makeProcessor(TikaClient $tika, PdfRasterizer $rasterizer): FileProcessor
    {
        return new FileProcessor(
            $tika,
            $rasterizer,
            new TextCleaner(),
            $this->aiFacade,
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
}
