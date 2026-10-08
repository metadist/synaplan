<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Provider\PiperProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Callers that send no format keep Piper's WebM stream; an explicit non-WebM
 * format (the iOS CarPlay client asks for mp3) is answered with one WAV body,
 * because AVFoundation cannot play WebM/Opus.
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
     * @param list<array{method: string, url: string}> $requests
     */
    private function provider(MockResponse $response, array &$requests): PiperProvider
    {
        $client = new MockHttpClient(function (string $method, string $url) use ($response, &$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url];

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

    public function testExplicitMp3IsAnsweredWithOneWavBody(): void
    {
        $requests = [];
        $provider = $this->provider(new MockResponse('RIFF-wav-bytes'), $requests);
        $options = ['language' => 'en', 'format' => 'mp3'];

        $chunks = iterator_to_array($provider->synthesizeStream('Hello', $options), false);

        self::assertSame(['RIFF-wav-bytes'], $chunks);
        self::assertSame('audio/wav', $provider->getStreamContentType($options));
        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('http://tts:10200/api/tts', $requests[0]['url']);
    }
}
