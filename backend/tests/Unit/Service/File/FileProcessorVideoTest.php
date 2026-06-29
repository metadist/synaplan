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
 * Issue #983 — FileProcessor must analyse video files by combining the
 * audio-track transcript with a visual key-frame description into one
 * labelled block. These tests pin that combination, including the
 * silent-video (visual-only) corner case.
 *
 * Review fix: external STT must fall back to local Whisper when it returns
 * empty or throws (e.g. "file too large" on WhatsApp videos before
 * extractAudioTrack() strips the video stream).
 */
class FileProcessorVideoTest extends TestCase
{
    private TikaClient&MockObject $tikaClient;
    private AiFacade&MockObject $aiFacade;
    private WhisperService&MockObject $whisperService;
    private VideoAnalysisService&MockObject $videoAnalysisService;
    private string $uploadDir;
    private FileProcessor $processor;

    protected function setUp(): void
    {
        $this->tikaClient = $this->createMock(TikaClient::class);
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->whisperService = $this->createMock(WhisperService::class);
        $this->videoAnalysisService = $this->createMock(VideoAnalysisService::class);

        $this->uploadDir = sys_get_temp_dir().'/synaplan-fileprocessor-video-'.bin2hex(random_bytes(6));
        mkdir($this->uploadDir, 0o775, true);

        $this->processor = new FileProcessor(
            $this->tikaClient,
            $this->createMock(PdfRasterizer::class),
            new TextCleaner(),
            $this->aiFacade,
            $this->whisperService,
            $this->videoAnalysisService,
            new HeicConverter(new NullLogger()),
            new NullLogger(),
            $this->uploadDir,
            tikaMinLength: 32,
            tikaMinEntropy: 2.0,
        );

        // No external STT and no local whisper -> external API transcription path.
        $this->whisperService->method('isAvailable')->willReturn(false);
        $this->aiFacade->method('hasConfiguredSttProvider')->willReturn(false);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->uploadDir.'/*') ?: []);
        if (is_dir($this->uploadDir)) {
            rmdir($this->uploadDir);
        }
    }

    public function testVideoCombinesTranscriptAndVisualDescription(): void
    {
        $relative = $this->writeBinaryVideo('clip.mp4');

        $this->aiFacade
            ->expects($this->once())
            ->method('transcribe')
            ->willReturn(['text' => 'Hello world.', 'provider' => 'openai']);

        $this->videoAnalysisService
            ->expects($this->once())
            ->method('describeKeyFrame')
            ->with($relative, 5)
            ->willReturn('A cat on a sofa.');

        [$text, $meta] = $this->processor->extractText($relative, 'mp4', 5);

        $this->assertSame(
            "[Visual description]\nA cat on a sofa.\n\n[Audio transcript]\nHello world.",
            $text,
        );
        $this->assertSame('video_transcript_vision', $meta['strategy']);
        $this->assertTrue($meta['has_transcript']);
        $this->assertTrue($meta['has_visual']);
    }

    public function testSilentVideoFallsBackToVisualOnly(): void
    {
        $relative = $this->writeBinaryVideo('silent.mov');

        $this->aiFacade
            ->method('transcribe')
            ->willReturn(['text' => '', 'provider' => 'openai']);

        $this->videoAnalysisService
            ->method('describeKeyFrame')
            ->willReturn('A whiteboard with diagrams.');

        [$text, $meta] = $this->processor->extractText($relative, 'mov', 5);

        $this->assertSame("[Visual description]\nA whiteboard with diagrams.", $text);
        $this->assertSame('video_vision', $meta['strategy']);
        $this->assertFalse($meta['has_transcript']);
        $this->assertTrue($meta['has_visual']);
    }

    public function testVideoWithNeitherTranscriptNorVisualReturnsEmpty(): void
    {
        $relative = $this->writeBinaryVideo('blank.mp4');

        $this->aiFacade->method('transcribe')->willReturn(['text' => '', 'provider' => 'openai']);
        $this->videoAnalysisService->method('describeKeyFrame')->willReturn(null);

        [$text, $meta] = $this->processor->extractText($relative, 'mp4', 5);

        $this->assertSame('', $text);
        $this->assertSame('video_failed', $meta['strategy']);
    }

    /**
     * Issue #983 review fix: when an external STT provider is configured and
     * returns empty (e.g. file-size rejection), the processor must fall back
     * to local Whisper instead of silently returning an empty transcript.
     *
     * Fresh mocks are used so setUp's willReturn(false) stubs cannot interfere.
     */
    public function testExternalSttEmptyFallsBackToLocalWhisper(): void
    {
        $relative = $this->writeBinaryVideo('whatsapp.mp4');

        // Build a fresh FileProcessor with the exact mock behaviour this test needs.
        $aiFacade = $this->createMock(AiFacade::class);
        $whisperService = $this->createMock(WhisperService::class);
        $videoAnalysisService = $this->createMock(VideoAnalysisService::class);

        $processor = $this->makeProcessor($aiFacade, $whisperService, $videoAnalysisService);

        // External STT configured but returns empty (simulates file-too-large rejection)
        $aiFacade->method('hasConfiguredSttProvider')->willReturn(true);
        $aiFacade
            ->expects($this->once())
            ->method('transcribe')
            ->willReturn(['text' => '', 'provider' => 'groq']);

        // Local Whisper available and returns a real transcript
        $whisperService->method('isAvailable')->willReturn(true);
        $whisperService
            ->expects($this->once())
            ->method('transcribe')
            ->willReturn(['text' => 'Content from local whisper.', 'language' => 'en', 'duration' => 5.2, 'model' => 'base']);

        $videoAnalysisService->method('describeKeyFrame')->willReturn('A speaker at a podium.');

        [$text, $meta] = $processor->extractText($relative, 'mp4', 7);

        $this->assertStringContainsString('Content from local whisper.', $text);
        $this->assertStringContainsString('A speaker at a podium.', $text);
        $this->assertSame('video_transcript_vision', $meta['strategy']);
        $this->assertTrue($meta['has_transcript']);
        // The local-whisper result is reflected in the audio_strategy slot
        $this->assertSame('whisper_local', $meta['audio_strategy']);
    }

    /**
     * When external STT throws a ProviderException AND local Whisper is
     * unavailable, the processor returns the external error result without
     * making a second request to the external provider.
     */
    public function testExternalSttThrowsAndNoLocalWhisperReturnsEmptyWithoutRetry(): void
    {
        $relative = $this->writeBinaryVideo('fail.mp4');

        $aiFacade = $this->createMock(AiFacade::class);
        $whisperService = $this->createMock(WhisperService::class);
        $videoAnalysisService = $this->createMock(VideoAnalysisService::class);

        $processor = $this->makeProcessor($aiFacade, $whisperService, $videoAnalysisService);

        $aiFacade->method('hasConfiguredSttProvider')->willReturn(true);
        $aiFacade
            ->expects($this->once()) // must NOT be called a second time
            ->method('transcribe')
            ->willThrowException(new ProviderException('quota exceeded', 'groq'));

        $whisperService->method('isAvailable')->willReturn(false);
        $videoAnalysisService->method('describeKeyFrame')->willReturn(null);

        [$text, $meta] = $processor->extractText($relative, 'mp4', 3);

        $this->assertSame('', $text);
        $this->assertSame('video_failed', $meta['strategy']);
    }

    /**
     * Build a FileProcessor with the provided mocks (and the shared uploadDir).
     */
    private function makeProcessor(
        AiFacade $aiFacade,
        WhisperService $whisperService,
        VideoAnalysisService $videoAnalysisService,
    ): FileProcessor {
        return new FileProcessor(
            $this->tikaClient,
            $this->createMock(PdfRasterizer::class),
            new TextCleaner(),
            $aiFacade,
            $whisperService,
            $videoAnalysisService,
            new HeicConverter(new NullLogger()),
            new NullLogger(),
            $this->uploadDir,
            tikaMinLength: 32,
            tikaMinEntropy: 2.0,
        );
    }

    /**
     * Write a small binary blob so mime_content_type() does NOT classify it
     * as plain text or an image, ensuring extractText() reaches the
     * audio/video transcription strategy.
     */
    private function writeBinaryVideo(string $name): string
    {
        $absolute = $this->uploadDir.'/'.$name;
        file_put_contents($absolute, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00".random_bytes(64));

        return $name;
    }
}
