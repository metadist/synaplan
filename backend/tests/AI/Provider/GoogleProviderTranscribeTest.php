<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Provider\GoogleProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class GoogleProviderTranscribeTest extends TestCase
{
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/syn_stt_test_'.uniqid('', true);
        mkdir($this->uploadDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->uploadDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->uploadDir);
    }

    public function testTranscribeSendsInlineAudioAndReadsTextAndDuration(): void
    {
        $wavPath = $this->writeTinyWav();
        $captured = ['method' => '', 'url' => '', 'json' => []];
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'hello from gemini']]],
            ]],
            'usageMetadata' => [
                'promptTokensDetails' => [
                    ['modality' => 'AUDIO', 'tokenCount' => 50],
                ],
            ],
        ]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use (&$captured, $response): ResponseInterface {
                $captured = ['method' => $method, 'url' => $url, 'json' => $options['json'] ?? []];

                return $response;
            });

        $provider = new GoogleProvider(
            new NullLogger(),
            $httpClient,
            'fake-api-key',
            null,
            'us-central1',
            $this->uploadDir,
        );

        $result = $provider->transcribe($wavPath, ['model' => 'gemini-3.5-transcribe']);

        $this->assertSame('POST', $captured['method']);
        $this->assertStringContainsString('models/gemini-3.5-transcribe:generateContent', (string) $captured['url']);
        $this->assertSame('hello from gemini', $result['text']);
        $this->assertEqualsWithDelta(2.0, (float) $result['duration'], 1e-9);
        $inline = $captured['json']['contents'][0]['parts'][0]['inlineData'] ?? [];
        $this->assertSame('audio/wav', $inline['mimeType'] ?? null);
        $this->assertNotEmpty($inline['data'] ?? null);
    }

    public function testTranslateAudioIsNotSupported(): void
    {
        $provider = new GoogleProvider(new NullLogger(), $this->createMock(HttpClientInterface::class), 'fake-api-key');

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('no dedicated translate mode');

        $provider->translateAudio('/tmp/x.wav', 'de');
    }

    public function testGetCapabilitiesIncludesSpeechToText(): void
    {
        $provider = new GoogleProvider(new NullLogger(), $this->createMock(HttpClientInterface::class), 'fake-api-key');

        $this->assertContains('speech_to_text', $provider->getCapabilities());
    }

    private function writeTinyWav(): string
    {
        $pcm = str_repeat("\x00\x00", 200);
        $dataSize = strlen($pcm);
        $header = 'RIFF'
            .pack('V', 36 + $dataSize)
            .'WAVEfmt '
            .pack('V', 16)
            .pack('v', 1)
            .pack('v', 1)
            .pack('V', 8000)
            .pack('V', 16000)
            .pack('v', 2)
            .pack('v', 16)
            .'data'
            .pack('V', $dataSize);
        $path = $this->uploadDir.'/sample.wav';
        file_put_contents($path, $header.$pcm);

        return $path;
    }
}
