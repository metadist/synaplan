<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Provider\PiperProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Callers that send no format keep Piper's WebM stream. AVFoundation cannot
 * play WebM/Opus, so an explicit mp3 (the iOS CarPlay client) is answered with
 * one MP3 body, and any other non-WebM format with one WAV body.
 */
final class PiperProviderStreamFormatTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir().'/syn_piper_format_'.uniqid();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->baseDir);
    }

    /**
     * @param list<array{method: string, url: string, body: string}> $requests
     */
    private function provider(MockResponse $response, array &$requests): PiperProvider
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($response, &$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'body' => (string) ($options['body'] ?? '')];

            return $response;
        });

        return new PiperProvider(
            $client,
            'http://tts:10200',
            new NullLogger(),
            new Filesystem(),
            $this->baseDir.'/temp',
            $this->baseDir.'/uploads',
        );
    }

    public function testWithoutFormatTheWebmStreamIsUnchanged(): void
    {
        $requests = [];
        $provider = $this->provider(new MockResponse('webm-bytes'), $requests);
        $options = ['language' => 'de'];

        $audio = implode('', iterator_to_array($provider->synthesizeStream('Hallo', $options), false));

        self::assertSame('webm-bytes', $audio);
        self::assertSame('audio/webm', $provider->getStreamContentType($options));
        self::assertSame('GET', $requests[0]['method']);
        self::assertStringContainsString('stream=true', $requests[0]['url']);
    }

    public function testWebmAndOpusRequestsKeepTheStream(): void
    {
        $requests = [];
        $provider = $this->provider(new MockResponse('webm-bytes'), $requests);

        self::assertSame('audio/webm', $provider->getStreamContentType(['format' => 'webm']));
        self::assertSame('audio/webm', $provider->getStreamContentType(['format' => ' OPUS ']));
    }

    public function testExplicitMp3IsAnsweredWithOneMp3Body(): void
    {
        self::requireFfmpeg();
        $requests = [];
        $provider = $this->provider(new MockResponse(self::silentWav()), $requests);
        $options = ['language' => 'en', 'format' => 'MP3'];

        $chunks = iterator_to_array($provider->synthesizeStream('Hello', $options), false);

        self::assertCount(1, $chunks);
        self::assertTrue(self::isMp3($chunks[0]), 'The body must be MP3, not the WAV Piper returned.');
        self::assertSame('audio/mpeg', $provider->getStreamContentType($options));
        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('http://tts:10200/api/tts', $requests[0]['url']);
    }

    public function testUndecodableAudioFailsTheMp3RequestInsteadOfSendingAnEmptyBody(): void
    {
        self::requireFfmpeg();
        $requests = [];
        $provider = $this->provider(new MockResponse('not-a-wav'), $requests);

        $this->expectException(ProviderException::class);

        $provider->synthesizeStream('Hello', ['language' => 'en', 'format' => 'mp3']);
    }

    #[DataProvider('wavFormats')]
    public function testOtherExplicitFormatsAreAnsweredWithOneWavBody(string $format): void
    {
        $requests = [];
        $provider = $this->provider(new MockResponse('RIFF-wav-bytes'), $requests);
        $options = ['language' => 'en', 'format' => $format];

        $chunks = iterator_to_array($provider->synthesizeStream('Hello', $options), false);

        self::assertSame(['RIFF-wav-bytes'], $chunks);
        self::assertSame('audio/wav', $provider->getStreamContentType($options));
        self::assertSame('POST', $requests[0]['method']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function wavFormats(): iterable
    {
        yield 'wav' => ['wav'];
        yield 'aac' => ['aac'];
        yield 'flac' => ['flac'];
    }

    /**
     * The controller can only answer with an error status while the request
     * is still inside its try block, i.e. before the generator is iterated.
     *
     * @param array<string, mixed> $options
     */
    #[DataProvider('streamOptions')]
    public function testPiperErrorIsThrownBeforeTheBodyIsIterated(array $options): void
    {
        $requests = [];
        $provider = $this->provider(new MockResponse('voice missing', ['http_code' => 500]), $requests);

        $this->expectException(ProviderException::class);

        $provider->synthesizeStream('Hello', $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('streamOptions')]
    public function testUnreachableServiceIsThrownBeforeTheBodyIsIterated(array $options): void
    {
        $requests = [];
        $provider = $this->provider(new MockResponse('', ['error' => 'Connection refused']), $requests);

        $this->expectException(TransportException::class);

        $provider->synthesizeStream('Hello', $options);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function streamOptions(): iterable
    {
        yield 'webm stream' => [['language' => 'en']];
        yield 'wav body' => [['language' => 'en', 'format' => 'mp3']];
    }

    #[DataProvider('speedToLengthScale')]
    public function testSpeedIsSentAsInverseLengthScale(float $speed, float $lengthScale): void
    {
        $requests = [];
        $provider = $this->provider(new MockResponse('RIFF-wav-bytes'), $requests);

        iterator_to_array($provider->synthesizeStream('Hello', ['language' => 'en', 'format' => 'wav', 'speed' => $speed]), false);

        $body = json_decode($requests[0]['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertEqualsWithDelta($lengthScale, $body['length_scale'], 0.0001);
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function speedToLengthScale(): iterable
    {
        yield 'normal' => [1.0, 1.0];
        yield 'twice as fast' => [2.0, 0.5];
        yield 'half speed' => [0.5, 2.0];
        yield 'clamped to slowest' => [0.0, 4.0];
    }

    private static function requireFfmpeg(): void
    {
        if (null === (new ExecutableFinder())->find('ffmpeg')) {
            self::markTestSkipped('ffmpeg is not installed.');
        }
    }

    /** 0.1 s of 16-bit mono PCM silence at 16 kHz, like a Piper "low" voice. */
    private static function silentWav(): string
    {
        $sampleRate = 16000;
        $data = str_repeat("\0\0", intdiv($sampleRate, 10));

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVE'
            .'fmt '.pack('VvvVVvv', 16, 1, 1, $sampleRate, $sampleRate * 2, 2, 16)
            .'data'.pack('V', strlen($data)).$data;
    }

    private static function isMp3(string $audio): bool
    {
        if (str_starts_with($audio, 'ID3')) {
            return true;
        }

        return strlen($audio) > 1 && "\xFF" === $audio[0] && (ord($audio[1]) & 0xE0) === 0xE0;
    }
}
