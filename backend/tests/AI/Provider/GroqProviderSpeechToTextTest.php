<?php

namespace App\Tests\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Provider\GroqProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for GroqProvider Speech-to-Text functionality.
 *
 * Tests the transcribe() and translateAudio() methods.
 */
class GroqProviderSpeechToTextTest extends TestCase
{
    private string $tempDir;
    private string $testAudioFile;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/groq_stt_test_'.uniqid();
        mkdir($this->tempDir, 0755, true);

        // Create a minimal test audio file
        $this->testAudioFile = $this->tempDir.'/test_audio.wav';
        $this->createTestWavFile($this->testAudioFile);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir.'/*');
            if ($files) {
                array_map('unlink', $files);
            }
            rmdir($this->tempDir);
        }
    }

    /**
     * Test that GroqProvider implements speech_to_text capability.
     */
    public function testCapabilitiesIncludeSpeechToText(): void
    {
        $provider = new GroqProvider(
            new NullLogger(),
            null, // No API key
            $this->tempDir
        );

        $capabilities = $provider->getCapabilities();

        $this->assertContains('speech_to_text', $capabilities);
        $this->assertContains('chat', $capabilities);
        $this->assertContains('vision', $capabilities);
    }

    /**
     * Test that transcribe throws exception when API key is missing.
     */
    public function testTranscribeThrowsExceptionWithoutApiKey(): void
    {
        $provider = new GroqProvider(
            new NullLogger(),
            null, // No API key
            $this->tempDir
        );

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('GROQ_API_KEY');

        $provider->transcribe('test_audio.wav');
    }

    /**
     * Test that transcribe throws exception when file not found.
     */
    public function testTranscribeThrowsExceptionForMissingFile(): void
    {
        $provider = new GroqProvider(
            new NullLogger(),
            'fake-api-key',
            $this->tempDir
        );

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Audio file not found');

        $provider->transcribe('nonexistent.wav');
    }

    /**
     * Test that transcribe throws exception when file is too large.
     */
    public function testTranscribeThrowsExceptionForLargeFile(): void
    {
        // Create a file larger than 25MB (we'll fake it with sparse file on supported systems)
        $largeFile = $this->tempDir.'/large_audio.wav';

        // Create a valid WAV header then truncate to 26MB
        $this->createTestWavFile($largeFile);
        file_put_contents($largeFile, str_repeat('x', 26 * 1024 * 1024));

        $provider = new GroqProvider(
            new NullLogger(),
            'fake-api-key',
            $this->tempDir
        );

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('too large');

        $provider->transcribe('large_audio.wav');
    }

    /**
     * Test that translateAudio throws exception when API key is missing.
     */
    public function testTranslateAudioThrowsExceptionWithoutApiKey(): void
    {
        $provider = new GroqProvider(
            new NullLogger(),
            null, // No API key
            $this->tempDir
        );

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('GROQ_API_KEY');

        $provider->translateAudio('test_audio.wav', 'en');
    }

    /**
     * Test that translateAudio throws exception when file not found.
     */
    public function testTranslateAudioThrowsExceptionForMissingFile(): void
    {
        $provider = new GroqProvider(
            new NullLogger(),
            'fake-api-key',
            $this->tempDir
        );

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Audio file not found');

        $provider->translateAudio('nonexistent.wav', 'en');
    }

    /**
     * Test provider metadata.
     */
    public function testProviderMetadata(): void
    {
        $provider = new GroqProvider(
            new NullLogger(),
            'test-api-key',
            $this->tempDir
        );

        $this->assertEquals('groq', $provider->getName());
        $this->assertEquals('Groq', $provider->getDisplayName());
        $this->assertStringContainsString('LPU', $provider->getDescription());
        $this->assertTrue($provider->isAvailable());
    }

    /**
     * Test provider is unavailable without API key.
     */
    public function testProviderUnavailableWithoutApiKey(): void
    {
        $provider = new GroqProvider(
            new NullLogger(),
            null,
            $this->tempDir
        );

        $this->assertFalse($provider->isAvailable());

        $status = $provider->getStatus();
        $this->assertFalse($status['healthy']);
        $this->assertStringContainsString('not configured', $status['error']);
    }

    /**
     * Create a minimal test WAV file (silence).
     */
    private function createTestWavFile(string $path): void
    {
        $sampleRate = 16000;
        $numChannels = 1;
        $bitsPerSample = 16;
        $duration = 1;

        $numSamples = $sampleRate * $duration;
        $dataSize = $numSamples * $numChannels * ($bitsPerSample / 8);
        $fileSize = $dataSize + 36;

        $header = pack(
            'a4Va4a4VvvVVvva4V',
            'RIFF',
            $fileSize,
            'WAVE',
            'fmt ',
            16,
            1,
            $numChannels,
            $sampleRate,
            $sampleRate * $numChannels * ($bitsPerSample / 8),
            $numChannels * ($bitsPerSample / 8),
            $bitsPerSample,
            'data',
            $dataSize
        );

        file_put_contents($path, $header.str_repeat("\0", $dataSize));
    }
}
