<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider\OpenAI;

use App\AI\Exception\ProviderException;
use App\AI\Provider\OpenAI\OpenAIApiClient;
use App\AI\Provider\OpenAI\OpenAICurlTransport;
use App\AI\Provider\OpenAI\OpenAISpeech;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenAISpeechTest extends TestCase
{
    use OpenAIApiDouble;

    private string $uploadDir = '';

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/openai-speech-'.bin2hex(random_bytes(4));
        mkdir($this->uploadDir);
        file_put_contents($this->uploadDir.'/voice.mp3', 'ID3-voice');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->uploadDir.'/*') ?: []);
        rmdir($this->uploadDir);
    }

    public function testATranscriptionSendsTheFileWithItsLanguageToGptTranscribe(): void
    {
        $sent = [];
        $speech = $this->transcriber($sent, new MockResponse(json_encode([
            'text' => 'Hallo',
            'languages' => [['code' => 'de']],
            'usage' => ['type' => 'duration', 'seconds' => 1.5],
        ], \JSON_THROW_ON_ERROR)));

        self::assertSame(['text' => 'Hallo', 'language' => 'de', 'duration' => 1.5, 'segments' => []], $speech->transcribe('voice.mp3', ['language' => 'de']));
        self::assertSame('https://api.openai.com/v1/audio/transcriptions', $sent['url']);
        self::assertSame(['Authorization: Bearer '.self::KEY], $sent['auth']);
        foreach (['name="model"', 'gpt-transcribe', 'name="languages[]"', 'name="response_format"', 'json', 'filename="voice.mp3"', 'ID3-voice'] as $part) {
            self::assertStringContainsString($part, $sent['body']);
        }
        self::assertStringNotContainsString('name="language"', $sent['body']);
        self::assertStringNotContainsString('verbose_json', $sent['body']);
    }

    public function testAnOlderTranscriptionModelGetsTheSingularLanguageField(): void
    {
        $sent = [];
        $speech = $this->transcriber($sent, new MockResponse('{"text":"Hallo"}'));

        $speech->transcribe('voice.mp3', ['model' => 'gpt-4o-transcribe', 'language' => 'de']);

        self::assertStringContainsString('name="language"', $sent['body']);
        self::assertStringNotContainsString('name="languages[]"', $sent['body']);
    }

    public function testAnUnhintedTranscriptionSendsNoLanguageAndReadsTheDurationField(): void
    {
        $sent = [];
        $speech = $this->transcriber($sent, new MockResponse('{"text":"Hi","language":"english","duration":2.25}'));

        self::assertSame(['text' => 'Hi', 'language' => 'english', 'duration' => 2.25, 'segments' => []], $speech->transcribe('voice.mp3', ['model' => 'gpt-transcribe']));
        self::assertStringNotContainsString('name="language"', $sent['body']);
    }

    public function testATranscriptionWithoutADurationUsageReportsZeroSeconds(): void
    {
        $sent = [];
        $speech = $this->transcriber($sent, new MockResponse('{"text":"Hi","languages":[],"usage":{"type":"tokens","input_tokens":12}}'));

        self::assertSame(['text' => 'Hi', 'language' => 'unknown', 'duration' => 0.0, 'segments' => []], $speech->transcribe('voice.mp3'));
    }

    public function testAFailedTranscriptionSaysWhatTheServerAnsweredWithoutThePath(): void
    {
        $sent = [];
        $speech = $this->transcriber($sent, new MockResponse('{"error":"bad format"}', ['http_code' => 400]));

        try {
            $speech->transcribe('voice.mp3');
            self::fail('A 400 must raise a provider error.');
        } catch (ProviderException $e) {
            self::assertStringContainsString('HTTP 400: {"error":"bad format"}', $e->getMessage());
            self::assertStringNotContainsString($this->uploadDir, $e->getMessage());
        }
    }

    public function testAMissingAudioFileDoesNotRevealTheUploadDirectory(): void
    {
        $sent = [];
        $speech = $this->transcriber($sent, new MockResponse('{}'));

        try {
            $speech->transcribe('missing.mp3');
            self::fail('A missing file must raise a provider error.');
        } catch (ProviderException $e) {
            self::assertStringContainsString('missing.mp3', $e->getMessage());
            self::assertStringNotContainsString($this->uploadDir, $e->getMessage());
            self::assertSame([], $sent, 'Nothing may be sent for a missing file.');
        }
    }

    public function testSynthesisWithoutAModelFailsInsteadOfPickingARetiredOne(): void
    {
        $speech = new OpenAISpeech($this->api(), new NullLogger(), $this->uploadDir);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('OpenAI TTS needs a model');

        iterator_to_array($speech->synthesizeStream('Hallo'), false);
    }

    /**
     * @param array<string, mixed> $sent receives the URL, auth header and flattened multipart body
     */
    private function transcriber(array &$sent, MockResponse $answer): OpenAISpeech
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent, $answer): MockResponse {
            $body = '';
            if ($options['body'] instanceof \Closure) {
                while ('' !== $chunk = ($options['body'])(8192)) {
                    $body .= $chunk;
                }
            } else {
                $body = (string) $options['body'];
            }
            $sent = [
                'url' => $url,
                'auth' => array_values(array_filter($options['headers'], static fn (string $h): bool => str_starts_with($h, 'Authorization:'))),
                'body' => $body,
            ];

            return $answer;
        });

        return new OpenAISpeech(new OpenAIApiClient($client, $this->createStub(OpenAICurlTransport::class), self::KEY), new NullLogger(), $this->uploadDir);
    }

    public function testTheSynthesisStreamArrivesAsItIsSent(): void
    {
        $speech = new OpenAISpeech($this->api(httpResponses: [new MockResponse(['ID3-', 'audio'])]), new NullLogger(), $this->uploadDir);

        self::assertSame(['ID3-', 'audio'], iterator_to_array($speech->synthesizeStream('Hallo', ['model' => 'tts-1', 'voice' => 'nova']), false));
        self::assertSame(['model' => 'tts-1', 'voice' => 'nova', 'input' => 'Hallo', 'response_format' => 'mp3', 'speed' => 1.0], $this->http->requests()[0]['body']);
    }

    public function testAFailedStreamSaysWhatTheServerAnswered(): void
    {
        $speech = new OpenAISpeech($this->api(httpResponses: [new MockResponse('{"error":"quota"}', ['http_code' => 429])]), new NullLogger(), $this->uploadDir);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('OpenAI TTS stream HTTP 429: {"error":"quota"}');

        iterator_to_array($speech->synthesizeStream('Hallo', ['model' => 'tts-1']), false);
    }
}
