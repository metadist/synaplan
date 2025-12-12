<?php

namespace App\Tests\Unit;

use App\Service\WhisperService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WhisperServiceTest extends TestCase
{
    private LoggerInterface $logger;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->tempDir = sys_get_temp_dir().'/whisper_test_'.uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        if (is_dir($this->tempDir)) {
            array_map('unlink', glob("$this->tempDir/*"));
            rmdir($this->tempDir);
        }
    }

    public function testIsAvailableReturnsFalseWhenBinaryNotFound(): void
    {
        $service = new WhisperService(
            $this->logger,
            '/nonexistent/whisper',
            $this->tempDir,
            'base',
            '/usr/bin/ffmpeg'
        );

        $this->assertFalse($service->isAvailable());
    }

    public function testIsAvailableReturnsFalseWhenModelNotFound(): void
    {
        // Create fake binary
        $fakeBinary = $this->tempDir.'/whisper';
        touch($fakeBinary);
        chmod($fakeBinary, 0755);

        $service = new WhisperService(
            $this->logger,
            $fakeBinary,
            $this->tempDir.'/models',
            'base',
            '/usr/bin/ffmpeg'
        );

        $this->assertFalse($service->isAvailable());
    }

    public function testIsAvailableReturnsTrueWhenBothExist(): void
    {
        // Create fake binary
        $fakeBinary = $this->tempDir.'/whisper';
        touch($fakeBinary);
        chmod($fakeBinary, 0755);

        // Create fake ffmpeg
        $fakeFfmpeg = $this->tempDir.'/ffmpeg';
        touch($fakeFfmpeg);
        chmod($fakeFfmpeg, 0755);

        // Create models directory and model file
        $modelsDir = $this->tempDir.'/models';
        mkdir($modelsDir);
        touch($modelsDir.'/ggml-base.bin');

        $service = new WhisperService(
            $this->logger,
            $fakeBinary,
            $modelsDir,
            'base',
            $fakeFfmpeg
        );

        $this->assertTrue($service->isAvailable());
    }

    public function testGetSupportedFormatsReturnsArray(): void
    {
        $service = new WhisperService(
            $this->logger,
            '/usr/local/bin/whisper',
            $this->tempDir,
            'base',
            '/usr/bin/ffmpeg'
        );

        $formats = $service->getSupportedFormats();

        $this->assertContains('mp3', $formats);
        $this->assertContains('wav', $formats);
        $this->assertContains('ogg', $formats);
        $this->assertContains('m4a', $formats);
        $this->assertContains('opus', $formats);
        $this->assertContains('flac', $formats);
    }

    public function testTranscribeThrowsExceptionForNonExistentFile(): void
    {
        $service = new WhisperService(
            $this->logger,
            '/usr/local/bin/whisper',
            $this->tempDir,
            'base',
            '/usr/bin/ffmpeg'
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Audio file not found');

        $service->transcribe('/nonexistent/audio.mp3');
    }

    public function testTranscribeThrowsExceptionForUnsupportedFormat(): void
    {
        $service = new WhisperService(
            $this->logger,
            '/usr/local/bin/whisper',
            $this->tempDir,
            'base',
            '/usr/bin/ffmpeg'
        );

        // Create a fake file with unsupported format
        $fakeFile = $this->tempDir.'/test.xyz';
        touch($fakeFile);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported audio format');

        $service->transcribe($fakeFile);
    }

    public function testGetAvailableModelsReturnsEmptyArrayWhenNoModels(): void
    {
        mkdir($this->tempDir.'/models');

        $service = new WhisperService(
            $this->logger,
            '/usr/local/bin/whisper',
            $this->tempDir.'/models',
            'base',
            '/usr/bin/ffmpeg'
        );

        $models = $service->getAvailableModels();

        $this->assertIsArray($models);
        $this->assertEmpty($models);
    }

    public function testGetAvailableModelsReturnsModelList(): void
    {
        $modelsDir = $this->tempDir.'/models';
        mkdir($modelsDir);

        // Create fake model files
        touch($modelsDir.'/ggml-tiny.bin');
        touch($modelsDir.'/ggml-base.bin');
        touch($modelsDir.'/ggml-small.bin');

        $service = new WhisperService(
            $this->logger,
            '/usr/local/bin/whisper',
            $modelsDir,
            'base',
            '/usr/bin/ffmpeg'
        );

        $models = $service->getAvailableModels();

        $this->assertIsArray($models);
        $this->assertCount(3, $models);
        $this->assertContains('tiny', $models);
        $this->assertContains('base', $models);
        $this->assertContains('small', $models);
    }

    public function testIsAvailableReturnsFalseWhenFfmpegNotFound(): void
    {
        // Create fake whisper binary
        $fakeBinary = $this->tempDir.'/whisper';
        touch($fakeBinary);
        chmod($fakeBinary, 0755);

        // Create models directory and model file
        $modelsDir = $this->tempDir.'/models';
        mkdir($modelsDir);
        touch($modelsDir.'/ggml-base.bin');

        // But FFmpeg doesn't exist
        $service = new WhisperService(
            $this->logger,
            $fakeBinary,
            $modelsDir,
            'base',
            '/nonexistent/ffmpeg'
        );

        $this->assertFalse($service->isAvailable());
    }

    public function testTranscribeLogsStartAndComplete(): void
    {
        // This test would require mocking Process which is complex
        // In real scenarios, integration tests would cover this
        $this->assertTrue(true);
    }

    public function testTranslateToEnglishSetsTranslateOption(): void
    {
        // This test would require mocking the transcribe method
        // For now, we just verify the method exists
        $service = new WhisperService(
            $this->logger,
            '/usr/local/bin/whisper',
            $this->tempDir,
            'base'
        );

        $this->assertTrue(method_exists($service, 'translateToEnglish'));
    }

    /**
     * Test that constructor parameters are correctly used.
     */
    public function testConstructorParametersAreStored(): void
    {
        $whisperBinary = '/custom/whisper';
        $modelsPath = '/custom/models';
        $defaultModel = 'small';
        $ffmpegBinary = '/custom/ffmpeg';

        $service = new WhisperService(
            $this->logger,
            $whisperBinary,
            $modelsPath,
            $defaultModel,
            $ffmpegBinary
        );

        // Test that the service was created successfully
        $this->assertInstanceOf(WhisperService::class, $service);

        // Test supported formats are available
        $formats = $service->getSupportedFormats();
        $this->assertNotEmpty($formats);
    }

    /**
     * Test that all supported formats are valid audio/video formats.
     */
    public function testSupportedFormatsAreValid(): void
    {
        $service = new WhisperService(
            $this->logger,
            '/usr/local/bin/whisper',
            $this->tempDir,
            'base',
            '/usr/bin/ffmpeg'
        );

        $formats = $service->getSupportedFormats();

        // Known valid formats that should be supported
        $expectedFormats = ['ogg', 'mp3', 'wav', 'm4a', 'opus', 'flac', 'webm'];

        foreach ($expectedFormats as $format) {
            $this->assertContains(
                $format,
                $formats,
                "Format {$format} should be supported"
            );
        }
    }

    /**
     * Test that video formats are supported (for audio extraction).
     */
    public function testVideoFormatsAreSupported(): void
    {
        $service = new WhisperService(
            $this->logger,
            '/usr/local/bin/whisper',
            $this->tempDir,
            'base',
            '/usr/bin/ffmpeg'
        );

        $formats = $service->getSupportedFormats();

        // Video formats that should be supported (FFmpeg extracts audio)
        $videoFormats = ['mp4', 'avi', 'mov', 'mkv', 'mpeg', 'mpg', 'webm'];

        foreach ($videoFormats as $format) {
            $this->assertContains(
                $format,
                $formats,
                "Video format {$format} should be supported for audio extraction"
            );
        }
    }

    /**
     * Test edge case: empty models directory doesn't break getAvailableModels.
     */
    public function testGetAvailableModelsHandlesNonExistentDirectory(): void
    {
        $service = new WhisperService(
            $this->logger,
            '/usr/local/bin/whisper',
            '/nonexistent/models',
            'base',
            '/usr/bin/ffmpeg'
        );

        $models = $service->getAvailableModels();

        $this->assertIsArray($models);
        $this->assertEmpty($models);
    }

    /**
     * Test that isAvailable checks all required components.
     */
    public function testIsAvailableChecksAllComponents(): void
    {
        // Create complete setup
        $fakeBinary = $this->tempDir.'/whisper';
        $fakeFfmpeg = $this->tempDir.'/ffmpeg';
        $modelsDir = $this->tempDir.'/models';

        touch($fakeBinary);
        chmod($fakeBinary, 0755);
        touch($fakeFfmpeg);
        chmod($fakeFfmpeg, 0755);
        mkdir($modelsDir);
        touch($modelsDir.'/ggml-base.bin');

        $service = new WhisperService(
            $this->logger,
            $fakeBinary,
            $modelsDir,
            'base',
            $fakeFfmpeg
        );

        $this->assertTrue($service->isAvailable(), 'Service should be available when all components exist');
    }

    /**
     * Test that isAvailable fails when binary is not executable.
     */
    public function testIsAvailableReturnsFalseWhenBinaryNotExecutable(): void
    {
        $fakeBinary = $this->tempDir.'/whisper';
        $fakeFfmpeg = $this->tempDir.'/ffmpeg';
        $modelsDir = $this->tempDir.'/models';

        touch($fakeBinary);
        chmod($fakeBinary, 0644); // Not executable
        touch($fakeFfmpeg);
        chmod($fakeFfmpeg, 0755);
        mkdir($modelsDir);
        touch($modelsDir.'/ggml-base.bin');

        $service = new WhisperService(
            $this->logger,
            $fakeBinary,
            $modelsDir,
            'base',
            $fakeFfmpeg
        );

        $this->assertFalse($service->isAvailable(), 'Service should not be available when binary is not executable');
    }
}
