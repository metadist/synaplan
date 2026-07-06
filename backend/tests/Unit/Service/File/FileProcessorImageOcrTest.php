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
 * Issue #1027 — when a text-less image is OCR'd, vision models often ignore the
 * "return empty string" instruction and answer with prose ("There is no visible
 * text in the image ..."). That prose must not be stored as fileText, otherwise
 * it pollutes the RAG index. These tests pin the normalisation to empty.
 */
final class FileProcessorImageOcrTest extends TestCase
{
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private AiFacade&MockObject $aiFacade;
    private FileProcessor $processor;
    private string $imagePath;
    private string $imageRelative;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);

        $this->processor = new FileProcessor(
            $this->createStub(TikaClient::class),
            $this->createStub(PdfRasterizer::class),
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

        $this->imageRelative = 'file_processor_ocr_test_'.uniqid().'.png';
        $this->imagePath = sys_get_temp_dir().'/'.$this->imageRelative;
        file_put_contents($this->imagePath, (string) base64_decode(self::PNG_1X1, true));
    }

    protected function tearDown(): void
    {
        if (file_exists($this->imagePath)) {
            @unlink($this->imagePath);
        }
    }

    public function testRefusalMessageIsStoredAsEmptyString(): void
    {
        $this->aiFacade->method('analyzeImage')->willReturn([
            'content' => 'I have analyzed the image and its crops. There is no visible text '
                .'in the image. Therefore, I will return an empty string as requested.',
            'provider' => 'openai',
        ]);

        [$text, $meta] = $this->processor->extractText($this->imageRelative, 'png', 1);

        self::assertSame('', $text);
        self::assertSame('vision_ai', $meta['strategy']);
    }

    public function testGenuineOcrTextIsPreserved(): void
    {
        $this->aiFacade->method('analyzeImage')->willReturn([
            'content' => "Invoice #4711\nTotal: 42.00 EUR\nThank you for your business",
            'provider' => 'openai',
        ]);

        [$text] = $this->processor->extractText($this->imageRelative, 'png', 1);

        self::assertStringContainsString('Invoice #4711', $text);
    }
}
