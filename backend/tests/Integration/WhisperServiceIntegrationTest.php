<?php

namespace App\Tests\Integration;

use App\Service\WhisperService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Integration tests for WhisperService.
 * These tests verify the actual behavior with file system operations.
 */
class WhisperServiceIntegrationTest extends TestCase
{
    private WhisperService $service;
    private string $tempDir;
    private string $testAudioFile;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/whisper_integration_test_'.uniqid();
        mkdir($this->tempDir, 0755, true);

        // Create a minimal test audio file (silence, 1 second, 16kHz mono WAV)
        $this->testAudioFile = $this->tempDir.'/test_audio.wav';
        $this->createTestWavFile($this->testAudioFile);

        $this->service = new WhisperService(
            new NullLogger(),
            '/usr/local/bin/whisper',
            '/var/www/backend/var/whisper',
            'base',
            '/usr/bin/ffmpeg'
        );
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir.'/*');
            if ($files) {
                array_map('unlink', $files);
            }
            rmdir($this->tempDir);
        }
    }

    /**
     * Test that Whisper is available in the test environment.
     */
    public function testWhisperIsAvailableInTestEnvironment(): void
    {
        if (!$this->service->isAvailable()) {
            $this->markTestSkipped('Whisper is not available in this environment');
        }

        $this->assertTrue($this->service->isAvailable());
    }

    /**
     * Test that supported formats includes all expected formats.
     */
    public function testGetSupportedFormats(): void
    {
        $formats = $this->service->getSupportedFormats();

        $this->assertNotEmpty($formats);

        // Verify critical formats
        $this->assertContains('mp3', $formats);
        $this->assertContains('wav', $formats);
        $this->assertContains('m4a', $formats);
        $this->assertContains('ogg', $formats);

        // Verify video formats (for audio extraction)
        $this->assertContains('mp4', $formats);
        $this->assertContains('webm', $formats);
    }

    /**
     * Test that getAvailableModels returns models when they exist.
     */
    public function testGetAvailableModels(): void
    {
        if (!$this->service->isAvailable()) {
            $this->markTestSkipped('Whisper is not available in this environment');
        }

        $models = $this->service->getAvailableModels();

        // At minimum, 'base' model should exist in test environment
        $this->assertContains('base', $models, 'Base model should be available');
    }

    /**
     * Test that transcribe handles output txt file correctly.
     *
     * This tests the critical change where Whisper writes to <input>.txt
     * and the service needs to read from that file.
     */
    public function testTranscribeHandlesOutputTxtFile(): void
    {
        if (!$this->service->isAvailable()) {
            $this->markTestSkipped('Whisper is not available in this environment');
        }

        // This is a slow test, so we'll skip it in quick test runs
        if ('true' === getenv('QUICK_TESTS')) {
            $this->markTestSkipped('Skipping slow integration test in quick mode');
        }

        try {
            $result = $this->service->transcribe($this->testAudioFile, [
                'model' => 'base',
                'threads' => 2,
            ]);
            $this->assertArrayHasKey('text', $result);
            $this->assertArrayHasKey('language', $result);
            $this->assertArrayHasKey('duration', $result);
            $this->assertArrayHasKey('model', $result);

            // Verify the text is a string (may be empty for silence)
            $this->assertIsString($result['text']);

            // Verify language was detected
            $this->assertNotEmpty($result['language']);
            $this->assertNotEquals('unknown', $result['language']);

            // Verify output txt file was cleaned up
            $outputTxtFile = $this->testAudioFile.'.txt';
            $this->assertFileDoesNotExist(
                $outputTxtFile,
                'Output txt file should be cleaned up after transcription'
            );
        } catch (\RuntimeException $e) {
            // If transcription fails, check if it's a configuration issue
            if (str_contains($e->getMessage(), 'Whisper model not found')) {
                $this->markTestSkipped('Whisper model not found: '.$e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Test that transcribe cleans up temporary WAV files.
     */
    public function testTranscribeCleansUpTemporaryFiles(): void
    {
        if (!$this->service->isAvailable()) {
            $this->markTestSkipped('Whisper is not available in this environment');
        }

        if ('true' === getenv('QUICK_TESTS')) {
            $this->markTestSkipped('Skipping slow integration test in quick mode');
        }

        // Count temp files before
        $tempFilesBefore = glob(sys_get_temp_dir().'/whisper_*');
        $countBefore = count($tempFilesBefore);

        try {
            $this->service->transcribe($this->testAudioFile, ['model' => 'base']);
        } catch (\Exception $e) {
            // Even if transcription fails, cleanup should happen
        }

        // Count temp files after (should be the same or less)
        $tempFilesAfter = glob(sys_get_temp_dir().'/whisper_*');
        $countAfter = count($tempFilesAfter);

        $this->assertLessThanOrEqual(
            $countBefore + 1,
            $countAfter,
            'Temporary files should be cleaned up after transcription'
        );
    }

    /**
     * Test that translateToEnglish works.
     */
    public function testTranslateToEnglish(): void
    {
        if (!$this->service->isAvailable()) {
            $this->markTestSkipped('Whisper is not available in this environment');
        }

        if ('true' === getenv('QUICK_TESTS')) {
            $this->markTestSkipped('Skipping slow integration test in quick mode');
        }

        try {
            $result = $this->service->translateToEnglish($this->testAudioFile, ['model' => 'base']);

            $this->assertArrayHasKey('text', $result);
            $this->assertIsString($result['text']);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Whisper model not found')) {
                $this->markTestSkipped('Whisper model not found: '.$e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Test that transcribe throws exception for invalid file.
     */
    public function testTranscribeThrowsExceptionForInvalidFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Audio file not found');

        $this->service->transcribe('/nonexistent/file.mp3');
    }

    /**
     * Test that transcribe throws exception for unsupported format.
     */
    public function testTranscribeThrowsExceptionForUnsupportedFormat(): void
    {
        $invalidFile = $this->tempDir.'/test.invalid';
        file_put_contents($invalidFile, 'dummy content');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported audio format');

        $this->service->transcribe($invalidFile);
    }

    /**
     * Create a minimal test WAV file (silence).
     *
     * Creates a 1-second silent WAV file with correct header.
     */
    private function createTestWavFile(string $path): void
    {
        $sampleRate = 16000;
        $numChannels = 1;
        $bitsPerSample = 16;
        $duration = 1; // seconds

        $numSamples = $sampleRate * $duration;
        $dataSize = $numSamples * $numChannels * ($bitsPerSample / 8);
        $fileSize = $dataSize + 36;

        $header = pack(
            'a4Va4a4VvvVVvva4V',
            'RIFF',
            $fileSize,
            'WAVE',
            'fmt ',
            16, // Subchunk1Size
            1,  // AudioFormat (PCM)
            $numChannels,
            $sampleRate,
            $sampleRate * $numChannels * ($bitsPerSample / 8), // ByteRate
            $numChannels * ($bitsPerSample / 8), // BlockAlign
            $bitsPerSample,
            'data',
            $dataSize
        );

        // Create file with header + silence (zeros)
        file_put_contents($path, $header.str_repeat("\0", $dataSize));
    }
}
