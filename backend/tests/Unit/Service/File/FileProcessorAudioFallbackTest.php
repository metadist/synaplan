<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\AI\Exception\ProviderException;
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
 * Unit tests for the audio/video transcription strategy in FileProcessor.
 *
 * Covers the fallback logic introduced to address PR #1095 Finding 2:
 * when an external STT provider is configured but returns empty text or
 * throws, local Whisper.cpp must be tried before giving up.
 *
 * Also covers the isTranscribableMediaExtension() helper used by
 * FileUploadService (Finding 3) to distinguish transcription failures
 * from legitimate empty-extraction results.
 */
final class FileProcessorAudioFallbackTest extends TestCase
{
    private AiFacade&MockObject $aiFacade;
    private WhisperService&MockObject $whisperService;
    private FileProcessor $processor;

    protected function setUp(): void
    {
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->whisperService = $this->createMock(WhisperService::class);

        $this->processor = new FileProcessor(
            $this->createStub(TikaClient::class),
            $this->createStub(PdfRasterizer::class),
            new TextCleaner(),
            $this->aiFacade,
            $this->whisperService,
            $this->createStub(VideoAnalysisService::class),
            new HeicConverter(new NullLogger()),
            new NullLogger(),
            sys_get_temp_dir(),
            100,
            0.5,
            '/nonexistent/ffmpeg',
        );
    }

    public function testExternalSttFallsBackToLocalWhisperWhenProviderThrows(): void
    {
        $this->aiFacade->method('hasConfiguredSttProvider')->willReturn(true);
        $this->aiFacade->method('transcribe')
            ->willThrowException(new ProviderException('Service unavailable', 'groq'));

        $this->whisperService->method('isAvailable')->willReturn(true);
        $this->whisperService->method('transcribe')->willReturn([
            'text' => 'Local fallback transcript',
            'language' => 'en',
            'duration' => 5.0,
            'model' => 'base',
        ]);

        [$text, $meta] = $this->processor->extractText('audio.mp3', 'mp3', 1);

        $this->assertSame('Local fallback transcript', $text);
        $this->assertSame('whisper_local', $meta['strategy']);
    }

    public function testExternalSttFallsBackToLocalWhisperWhenProviderReturnsEmpty(): void
    {
        $this->aiFacade->method('hasConfiguredSttProvider')->willReturn(true);
        $this->aiFacade->method('transcribe')->willReturn(['text' => '', 'provider' => 'groq']);

        $this->whisperService->method('isAvailable')->willReturn(true);
        $this->whisperService->method('transcribe')->willReturn([
            'text' => 'Whisper fallback transcript',
            'language' => 'de',
            'duration' => 10.0,
            'model' => 'base',
        ]);

        [$text, $meta] = $this->processor->extractText('audio.mp3', 'mp3', 1);

        $this->assertSame('Whisper fallback transcript', $text);
        $this->assertSame('whisper_local', $meta['strategy']);
    }

    public function testExternalSttSuccessSkipsLocalWhisper(): void
    {
        $this->aiFacade->method('hasConfiguredSttProvider')->willReturn(true);
        $this->aiFacade->method('transcribe')->willReturn([
            'text' => 'External transcript',
            'provider' => 'openai',
        ]);

        $this->whisperService->expects($this->never())->method('transcribe');
        $this->whisperService->expects($this->never())->method('isAvailable');

        [$text, $meta] = $this->processor->extractText('audio.mp3', 'mp3', 1);

        $this->assertSame('External transcript', $text);
        $this->assertSame('whisper_api', $meta['strategy']);
    }

    public function testLocalWhisperUsedFirstWhenNoExternalProviderConfigured(): void
    {
        $this->aiFacade->method('hasConfiguredSttProvider')->willReturn(false);
        $this->aiFacade->expects($this->never())->method('transcribe');

        $this->whisperService->method('isAvailable')->willReturn(true);
        $this->whisperService->method('transcribe')->willReturn([
            'text' => 'Local first transcript',
            'language' => 'en',
            'duration' => 2.0,
            'model' => 'base',
        ]);

        [$text, $meta] = $this->processor->extractText('audio.wav', 'wav', null);

        $this->assertSame('Local first transcript', $text);
        $this->assertSame('whisper_local', $meta['strategy']);
    }

    public function testExternalUsedAsFallbackWhenLocalUnavailableAndNoProviderConfigured(): void
    {
        $this->aiFacade->method('hasConfiguredSttProvider')->willReturn(false);
        $this->aiFacade->method('transcribe')->willReturn([
            'text' => 'External as last resort',
            'provider' => 'openai',
        ]);

        $this->whisperService->method('isAvailable')->willReturn(false);
        $this->whisperService->expects($this->never())->method('transcribe');

        [$text, $meta] = $this->processor->extractText('audio.mp3', 'mp3', null);

        $this->assertSame('External as last resort', $text);
        $this->assertSame('whisper_api', $meta['strategy']);
    }

    public function testBothPathsFailReturnsEmptyString(): void
    {
        $this->aiFacade->method('hasConfiguredSttProvider')->willReturn(true);
        $this->aiFacade->method('transcribe')->willReturn(['text' => '', 'provider' => 'groq']);

        $this->whisperService->method('isAvailable')->willReturn(false);

        [$text, $meta] = $this->processor->extractText('audio.mp3', 'mp3', 1);

        $this->assertSame('', $text);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('transcribableExtensionsProvider')]
    public function testIsTranscribableMediaExtensionReturnsTrueForMediaFiles(string $ext): void
    {
        $this->assertTrue(FileProcessor::isTranscribableMediaExtension($ext));
        $this->assertTrue(FileProcessor::isTranscribableMediaExtension(strtoupper($ext)), 'Case-insensitive check');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function transcribableExtensionsProvider(): array
    {
        return [
            'mp3' => ['mp3'],
            'mp4' => ['mp4'],
            'wav' => ['wav'],
            'ogg' => ['ogg'],
            'mov' => ['mov'],
            'avi' => ['avi'],
            'mkv' => ['mkv'],
            'mpeg' => ['mpeg'],
            'mpg' => ['mpg'],
            'flac' => ['flac'],
            'm4a' => ['m4a'],
            'webm' => ['webm'],
            'aac' => ['aac'],
            'wma' => ['wma'],
            'amr' => ['amr'],
            'opus' => ['opus'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonTranscribableExtensionsProvider')]
    public function testIsTranscribableMediaExtensionReturnsFalseForNonMedia(string $ext): void
    {
        $this->assertFalse(FileProcessor::isTranscribableMediaExtension($ext));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonTranscribableExtensionsProvider(): array
    {
        return [
            'pdf' => ['pdf'],
            'docx' => ['docx'],
            'txt' => ['txt'],
            'jpg' => ['jpg'],
            'png' => ['png'],
            'xlsx' => ['xlsx'],
            'csv' => ['csv'],
            'pptx' => ['pptx'],
        ];
    }
}
