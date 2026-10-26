<?php

declare(strict_types=1);

namespace App\Tests\Plugin\SynaScriber;

use Plugin\SynaScriber\Service\SessionException;
use Plugin\SynaScriber\Service\WhisperClient;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WhisperClientTest extends PluginTestCase
{
    /**
     * @param array<string, mixed> $body
     */
    private function client(array $body, int $status = 200): WhisperClient
    {
        $http = new MockHttpClient(new MockResponse((string) json_encode($body), ['http_code' => $status]));

        return new WhisperClient($http, $this->settings(), new NullLogger());
    }

    /**
     * @param list<array{0: float, 1: float, 2: string}> $segments start, end (s), text
     *
     * @return array<string, mixed>
     */
    private static function body(array $segments): array
    {
        return [
            'text' => implode('', array_column($segments, 2)),
            'segments' => array_map(static fn (array $s): array => ['start' => $s[0], 'end' => $s[1], 'text' => $s[2], 'no_speech_prob' => 0.01, 'avg_logprob' => -0.2], $segments),
        ];
    }

    public function testSpeechIsJoinedWithPhraseEnds(): void
    {
        $result = $this->client(self::body([[0.3, 1.7, ' Guten Morgen'], [1.9, 3.25, ' zusammen ,'], [3.4, 4.1, ' wir fangen an.']]))->recognize('OggS', 'de');

        self::assertSame('Guten Morgen zusammen, wir fangen an.', $result['text']);
        self::assertNull($result['dropped']);
        self::assertSame([1700, 3250, 4100], $result['segmentEndsMs']);
        self::assertSame(4100, $result['speechEndMs']);
    }

    public function testNoSegmentsIsNoSpeech(): void
    {
        $result = $this->client(['text' => '', 'segments' => []])->recognize('OggS', 'de');

        self::assertSame(['text' => '', 'dropped' => 'no_speech', 'speechEndMs' => null, 'segmentEndsMs' => []], $result);
    }

    public function testSilencePhrasesAreHallucinations(): void
    {
        foreach ([' Vielen Dank fürs Zuschauen.', ' Untertitel im Auftrag des ZDF, 2021', ' Amen.'] as $phrase) {
            $result = $this->client(self::body([[0.0, 2.0, $phrase]]))->recognize('OggS', 'de');
            self::assertSame('', $result['text'], $phrase);
            self::assertSame('hallucination', $result['dropped'], $phrase);
            self::assertSame([], $result['segmentEndsMs'], $phrase);
        }
    }

    public function testRepetitionLoopIsDropped(): void
    {
        $result = $this->client(self::body([[0.0, 6.0, str_repeat(' ja ja', 6)]]))->recognize('OggS', 'de');

        self::assertSame('hallucination', $result['dropped']);
    }

    public function testUnlikelySpeechIsSkipped(): void
    {
        $body = ['segments' => [['start' => 0.0, 'end' => 1.0, 'text' => ' hm', 'no_speech_prob' => 0.9, 'avg_logprob' => -1.4]]];

        self::assertSame('no_speech', $this->client($body)->recognize('OggS', 'de')['dropped']);
    }

    public function testUnreachableServerIsReported(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => throw new TransportException('connection refused'));
        $client = new WhisperClient($http, $this->settings(), new NullLogger());

        $this->expectException(SessionException::class);
        $this->expectExceptionMessage('did not answer');
        $client->recognize('OggS', 'de');
    }

    public function testServerErrorIsReported(): void
    {
        try {
            $this->client(['error' => 'boom'], 500)->recognize('OggS', 'de');
            self::fail('A 500 must not count as silence.');
        } catch (SessionException $e) {
            self::assertSame('stt_error', $e->errorCode);
        }
    }

    public function testTheModelFieldIsSentWithTheDefaultName(): void
    {
        $sent = $this->recognize();

        self::assertSame('POST', $sent['method']);
        self::assertSame('http://stt.test/v1/audio/transcriptions', $sent['url']);
        self::assertStringContainsString('name="model"'."\r\n\r\n".'whisper'."\r\n", $sent['body']);
        self::assertStringContainsString('name="file"; filename="window.ogg"', $sent['body']);
        self::assertStringContainsString('name="language"'."\r\n\r\n".'de'."\r\n", $sent['body']);
        self::assertNotContains('Authorization', $this->headerNames($sent['headers']));
    }

    public function testTheConfiguredModelAndKeyGoOut(): void
    {
        $sent = $this->recognize(['stt_model' => 'openai/whisper-large-v3-turbo', 'stt_api_key' => 'k-123']);

        self::assertStringContainsString('name="model"'."\r\n\r\n".'openai/whisper-large-v3-turbo'."\r\n", $sent['body']);
        self::assertContains('Authorization: Bearer k-123', $sent['headers']);
    }

    public function testTheHealthCheckCarriesTheKey(): void
    {
        $sent = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = ['method' => $method, 'url' => $url, 'headers' => $options['headers']];

            return new MockResponse('ok');
        });

        self::assertTrue((new WhisperClient($http, $this->settings(['stt_api_key' => 'k-123']), new NullLogger()))->healthy());
        self::assertSame('http://stt.test/health', $sent['url']);
        self::assertContains('Authorization: Bearer k-123', $sent['headers']);
    }

    public function testAModelNameThatIsNotOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stt_model');

        $this->settings()->update(['stt_model' => 'whisper large']);
    }

    public function testTheKeyIsASecretOnThePluginPage(): void
    {
        $view = $this->settings(['stt_api_key' => 'k-123'])->publicView();

        self::assertTrue($view['stt_api_key']);
        self::assertSame('whisper', $view['stt_model']);
    }

    /**
     * @param array<string, string> $settings
     *
     * @return array{method: string, url: string, headers: list<string>, body: string}
     */
    private function recognize(array $settings = []): array
    {
        $sent = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = ['method' => $method, 'url' => $url, 'headers' => $options['headers'], 'body' => $this->drain($options['body'])];

            return new MockResponse((string) json_encode(['text' => 'Guten Tag', 'segments' => [['text' => ' Guten Tag', 'end' => 1.2, 'no_speech_prob' => 0.0, 'avg_logprob' => -0.2]]]));
        });
        $result = (new WhisperClient($http, $this->settings($settings), new NullLogger()))->recognize('OggS', 'de');
        self::assertSame('Guten Tag', $result['text']);

        return $sent;
    }

    /**
     * The multipart body reaches the client as chunks behind a closure.
     */
    private function drain(mixed $body): string
    {
        if (is_string($body)) {
            return $body;
        }
        if (is_iterable($body)) {
            $out = '';
            foreach ($body as $chunk) {
                $out .= $chunk;
            }

            return $out;
        }
        if (is_callable($body)) {
            $out = '';
            while ('' !== $chunk = (string) $body(16384)) {
                $out .= $chunk;
            }

            return $out;
        }

        return '';
    }

    /**
     * @param list<string> $headers
     *
     * @return list<string>
     */
    private function headerNames(array $headers): array
    {
        return array_map(static fn (string $line): string => explode(':', $line, 2)[0], $headers);
    }
}
