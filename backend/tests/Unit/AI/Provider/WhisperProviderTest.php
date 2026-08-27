<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Provider\WhisperProvider;
use App\Service\WhisperService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class WhisperProviderTest extends TestCase
{
    private WhisperService&MockObject $whisperService;
    private WhisperProvider $provider;

    protected function setUp(): void
    {
        $this->whisperService = $this->createMock(WhisperService::class);
        $this->provider = new WhisperProvider($this->whisperService);
    }

    public function testMetadata(): void
    {
        self::assertSame('whisper', $this->provider->getName());
        self::assertSame('Whisper', $this->provider->getDisplayName());
        self::assertContains('speech_to_text', $this->provider->getCapabilities());
    }

    public function testAvailabilityDelegatesToWhisperService(): void
    {
        $this->whisperService->method('isAvailable')->willReturn(true);

        self::assertTrue($this->provider->isAvailable());
        self::assertTrue($this->provider->getStatus()['healthy']);
    }

    public function testTranscribeForwardsToWhisperServiceAndDropsCatalogAlias(): void
    {
        $this->whisperService->method('isAvailable')->willReturn(true);
        $this->whisperService
            ->expects(self::once())
            ->method('transcribe')
            ->with('/tmp/a.wav', ['language' => 'de'])
            ->willReturn(['text' => 'hallo', 'language' => 'de', 'duration' => 1.2]);

        $result = $this->provider->transcribe('/tmp/a.wav', ['model' => 'whisper', 'language' => 'de']);

        self::assertSame('hallo', $result['text']);
        self::assertSame(1.2, $result['duration']);
    }

    public function testTranscribeThrowsWhenUnavailable(): void
    {
        $this->whisperService->method('isAvailable')->willReturn(false);

        $this->expectException(ProviderException::class);
        $this->provider->transcribe('/tmp/a.wav');
    }

    public function testTranslateAudioEnablesTranslateWithoutUsingTargetAsInputHint(): void
    {
        $this->whisperService->method('isAvailable')->willReturn(true);
        $this->whisperService
            ->expects(self::once())
            ->method('transcribe')
            ->with('/tmp/a.wav', ['translate' => true])
            ->willReturn(['text' => 'hello']);

        self::assertSame('hello', $this->provider->translateAudio('/tmp/a.wav', 'de'));
    }
}
