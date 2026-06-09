<?php

namespace App\Tests\AI\Provider;

use App\AI\Provider\GoogleProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Regression: Gemini TTS returns headerless linear-16 PCM with the mime
 * "audio/L16;rate=24000;channels=1" (RFC 2586). The provider must wrap it in a
 * RIFF/WAVE container so browsers can decode it — otherwise the saved .wav is
 * raw PCM and playback fails ("audio not available").
 */
class GoogleProviderTtsWavTest extends TestCase
{
    private string $uploadDir;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/syn_tts_test_'.uniqid();
        mkdir($this->uploadDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->uploadDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->uploadDir);
    }

    private function provider(array $responseData): GoogleProvider
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->willReturn($responseData);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        return new GoogleProvider(
            new NullLogger(),
            $httpClient,
            'fake-api-key',
            null,
            'us-central1',
            $this->uploadDir,
        );
    }

    public function testL16PcmIsWrappedInWavContainer(): void
    {
        // 200 samples of silence as raw signed 16-bit PCM (the headerless bytes
        // Gemini returns). With no fix these bytes would be saved verbatim.
        $pcm = str_repeat("\x00\x00", 200);
        $data = [
            'candidates' => [[
                'content' => ['parts' => [[
                    'inlineData' => [
                        'mimeType' => 'audio/L16;rate=24000;channels=1',
                        'data' => base64_encode($pcm),
                    ],
                ]]],
            ]],
        ];

        $provider = $this->provider($data);
        $filename = $provider->synthesize('hello world', ['model' => 'gemini-3.1-flash-tts-preview']);

        self::assertStringEndsWith('.wav', $filename);
        $bytes = (string) file_get_contents($this->uploadDir.'/'.$filename);

        // Valid RIFF/WAVE header must precede the PCM payload.
        self::assertSame('RIFF', substr($bytes, 0, 4), 'missing RIFF marker (PCM not wrapped)');
        self::assertSame('WAVE', substr($bytes, 8, 4), 'missing WAVE marker');
        self::assertSame('fmt ', substr($bytes, 12, 4));
        self::assertSame('data', substr($bytes, 36, 4));

        // The header carries the parsed sample rate (24000) from the mime.
        $sampleRate = unpack('V', substr($bytes, 24, 4))[1];
        self::assertSame(24000, $sampleRate);

        // 44-byte header + the original PCM payload.
        self::assertSame(44 + strlen($pcm), strlen($bytes));
    }

    public function testRateAndChannelsParsedFromMime(): void
    {
        $pcm = str_repeat("\x01\x02", 100);
        $data = [
            'candidates' => [[
                'content' => ['parts' => [[
                    'inlineData' => [
                        'mimeType' => 'audio/l16;rate=16000;channels=2',
                        'data' => base64_encode($pcm),
                    ],
                ]]],
            ]],
        ];

        $provider = $this->provider($data);
        $filename = $provider->synthesize('x', ['model' => 'gemini-2.5-flash-preview-tts']);
        $bytes = (string) file_get_contents($this->uploadDir.'/'.$filename);

        self::assertSame('RIFF', substr($bytes, 0, 4));
        self::assertSame(16000, unpack('V', substr($bytes, 24, 4))[1], 'sample rate not parsed from mime');
        self::assertSame(2, unpack('v', substr($bytes, 22, 2))[1], 'channel count not parsed from mime');
    }
}
