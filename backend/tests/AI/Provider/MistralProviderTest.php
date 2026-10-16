<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Provider\MistralProvider;
use App\AI\StructuredOutput\StructuredOutputSchema;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Unit tests for MistralProvider.
 *
 * Chat runs through the openai-php client (built internally), so only its
 * preconditions are asserted here. The Voxtral audio endpoints use Symfony's
 * HttpClient and are exercised with {@see MockHttpClient}.
 */
class MistralProviderTest extends TestCase
{
    private const API_KEY = 'test-key';

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/mistral_test_'.uniqid();
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir.'/*') ?: [];
            array_map('unlink', $files);
            rmdir($this->tempDir);
        }
    }

    // ==================== METADATA ====================

    public function testMetadata(): void
    {
        $provider = $this->makeProvider();

        $this->assertSame('mistral', $provider->getName());
        $this->assertSame('Mistral AI', $provider->getDisplayName());
        $this->assertTrue($provider->isAvailable());
    }

    public function testCapabilities(): void
    {
        $capabilities = $this->makeProvider()->getCapabilities();

        $this->assertContains('chat', $capabilities);
        $this->assertContains('vision', $capabilities);
        $this->assertContains('speech_to_text', $capabilities);
        $this->assertContains('text_to_speech', $capabilities);
    }

    public function testStatusHealthyWhenConfigured(): void
    {
        $this->assertTrue($this->makeProvider()->getStatus()['healthy']);
    }

    public function testProviderUnavailableWithoutApiKey(): void
    {
        $provider = $this->makeProvider(apiKey: null);

        $this->assertFalse($provider->isAvailable());
        $status = $provider->getStatus();
        $this->assertFalse($status['healthy']);
        $this->assertStringContainsString('not configured', $status['error']);
    }

    public function testRequiredEnvVars(): void
    {
        $envVars = $this->makeProvider()->getRequiredEnvVars();

        $this->assertArrayHasKey('MISTRAL_API_KEY', $envVars);
        $this->assertTrue($envVars['MISTRAL_API_KEY']['required']);
        $this->assertStringContainsString('console.mistral.ai', $envVars['MISTRAL_API_KEY']['hint']);
    }

    // ==================== PRECONDITIONS ====================

    public function testChatThrowsWithoutModel(): void
    {
        $this->expectExceptionMessageContains('Model must be specified');
        $this->makeProvider()->chat([['role' => 'user', 'content' => 'hi']]);
    }

    public function testChatThrowsWithoutApiKey(): void
    {
        $this->expectExceptionMessageContains('MISTRAL_API_KEY');
        $this->makeProvider(apiKey: null)->chat([['role' => 'user', 'content' => 'hi']], ['model' => 'mistral-large-latest']);
    }

    public function testTranscribeThrowsWithoutApiKey(): void
    {
        $this->expectExceptionMessageContains('MISTRAL_API_KEY');
        $this->makeProvider(apiKey: null)->transcribe('audio.mp3');
    }

    public function testSynthesizeThrowsWithoutApiKey(): void
    {
        $this->expectExceptionMessageContains('MISTRAL_API_KEY');
        $this->makeProvider(apiKey: null)->synthesize('hello');
    }

    public function testExplainImageThrowsWithoutApiKey(): void
    {
        $this->expectExceptionMessageContains('MISTRAL_API_KEY');
        $this->makeProvider(apiKey: null)->explainImage('image.png');
    }

    public function testExtractTextFromImageThrowsWithoutApiKey(): void
    {
        $this->expectExceptionMessageContains('MISTRAL_API_KEY');
        $this->makeProvider(apiKey: null)->extractTextFromImage('image.png');
    }

    public function testExplainImageThrowsWhenFileMissing(): void
    {
        $this->expectExceptionMessageContains('Image file not found');
        $this->makeProvider()->explainImage('does-not-exist.png');
    }

    // ==================== STRUCTURED OUTPUT (Phase 2a) ====================

    public function testChatOptionsMergeStructuredOutputAsJsonSchema(): void
    {
        $request = $this->buildChatOptions([], [
            'model' => 'mistral-large-latest',
            'structured_output' => new StructuredOutputSchema('sort_result', ['type' => 'object']),
        ], false);

        $this->assertSame('json_schema', $request['response_format']['type']);
        $this->assertSame('sort_result', $request['response_format']['json_schema']['name']);
        $this->assertSame(['type' => 'object'], $request['response_format']['json_schema']['schema']);
    }

    public function testChatOptionsWithoutStructuredOutputOmitResponseFormat(): void
    {
        $request = $this->buildChatOptions([], ['model' => 'mistral-large-latest'], false);

        $this->assertArrayNotHasKey('response_format', $request);
    }

    public function testReasoningEffortIsSentOnlyWhenLarge4ThinkingIsOff(): void
    {
        $off = $this->buildChatOptions([], ['model' => 'mistral-large-4', 'reasoning' => false], false);
        $on = $this->buildChatOptions([], ['model' => 'mistral-large-4', 'reasoning' => true], false);
        $large3 = $this->buildChatOptions([], ['model' => 'mistral-large-latest', 'reasoning' => false], false);

        $this->assertSame('none', $off['reasoning_effort']);
        $this->assertArrayNotHasKey('reasoning_effort', $on);
        $this->assertArrayNotHasKey('reasoning_effort', $large3);
    }

    public function testChatPassesStringContentThrough(): void
    {
        $client = new MockHttpClient(static fn () => new MockResponse(json_encode([
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => 'hello'],
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]));

        $result = $this->makeProvider(httpClient: $client)->chat(
            [['role' => 'user', 'content' => 'hi']],
            ['model' => 'mistral-large-latest'],
        );

        $this->assertSame('hello', $result['content']);
    }

    public function testChatKeepsOnlyTheVisibleTextFromThinkingParts(): void
    {
        $client = new MockHttpClient(static fn () => new MockResponse(json_encode([
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => [
                    'role' => 'assistant',
                    'content' => [
                        ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'draft']]],
                        ['type' => 'text', 'text' => 'ok'],
                    ],
                ],
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 4, 'total_tokens' => 14],
        ], \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]));

        $result = $this->makeProvider(httpClient: $client)->chat(
            [['role' => 'user', 'content' => 'hi']],
            ['model' => 'mistral-large-4'],
        );

        $this->assertSame('ok', $result['content']);
        $this->assertSame(10, $result['usage']['prompt_tokens']);
    }

    public function testChatHttpErrorKeepsTheUpstreamStatusForClassification(): void
    {
        $client = new MockHttpClient(static fn () => new MockResponse(json_encode([
            'object' => 'error',
            'message' => 'Rate limit exceeded',
            'type' => 'rate_limited',
            'code' => '1300',
        ], \JSON_THROW_ON_ERROR), [
            'http_code' => 429,
            'response_headers' => ['content-type' => 'application/json'],
        ]));

        try {
            $this->makeProvider(httpClient: $client)->chat(
                [['role' => 'user', 'content' => 'hi']],
                ['model' => 'mistral-large-4'],
            );
            $this->fail('Expected a ProviderException');
        } catch (ProviderException $e) {
            $this->assertSame(429, $e->getContext()['status_code'] ?? null);
            $this->assertSame('rate_limited', $e->getContext()['error_type'] ?? null);
            $this->assertSame('chat', $e->getContext()['stage'] ?? null);
        }
    }

    public function testChatStreamErrorFrameFailsInsteadOfEndingEmpty(): void
    {
        $chunks = [
            'data: {"object":"error","message":"Internal error","type":"internal_error","code":"3000","raw_status_code":500}'."\n",
        ];
        $client = new MockHttpClient(static fn () => new MockResponse($chunks, [
            'response_headers' => ['content-type' => 'text/event-stream'],
        ]));

        $events = [];
        try {
            $this->makeProvider(httpClient: $client)->chatStream(
                [['role' => 'user', 'content' => 'hi']],
                static function (string|array $chunk) use (&$events): void {
                    $events[] = $chunk;
                },
                ['model' => 'mistral-large-4'],
            );
            $this->fail('Expected a ProviderException');
        } catch (ProviderException $e) {
            $this->assertSame(500, $e->getContext()['status_code'] ?? null);
            $this->assertSame('chat_stream', $e->getContext()['stage'] ?? null);
            $this->assertSame([], $events);
        }
    }

    public function testChatStreamSplitsThinkingPartsFromTheAnswer(): void
    {
        $chunks = [
            'data: {"choices":[{"delta":{"content":[{"type":"thinking","thinking":[{"type":"text","text":"draft"}]}]},"finish_reason":null}]}'."\n",
            'data: {"choices":[{"delta":{"content":[{"type":"text","text":"ok"}]},"finish_reason":"stop"}],"usage":{"prompt_tokens":3,"completion_tokens":2,"total_tokens":5}}'."\n"
                .'data: [DONE]'."\n",
        ];
        $client = new MockHttpClient(static fn () => new MockResponse($chunks, [
            'response_headers' => ['content-type' => 'text/event-stream'],
        ]));

        $events = [];
        $result = $this->makeProvider(httpClient: $client)->chatStream(
            [['role' => 'user', 'content' => 'hi']],
            static function (string|array $chunk) use (&$events): void {
                $events[] = $chunk;
            },
            ['model' => 'mistral-large-4'],
        );

        $this->assertSame([
            ['type' => 'reasoning', 'content' => 'draft'],
            'ok',
            ['type' => 'finish', 'finish_reason' => 'stop'],
        ], $events);
        $this->assertSame(3, $result['usage']['prompt_tokens']);
    }

    public function testTranslateAudioIsNotSupported(): void
    {
        $this->expectExceptionMessageContains('does not support audio translation');
        $this->makeProvider()->translateAudio('audio.mp3', 'en');
    }

    public function testTranscribeThrowsWhenFileMissing(): void
    {
        $this->expectExceptionMessageContains('Audio file not found');
        $this->makeProvider()->transcribe('does-not-exist.mp3');
    }

    // ==================== SPEECH TO TEXT ====================

    public function testTranscribePostsToVoxtralEndpointAndReturnsText(): void
    {
        $audioFile = $this->tempDir.'/sample.mp3';
        file_put_contents($audioFile, 'FAKEAUDIO');

        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'opts' => $opts];

            return new MockResponse(json_encode([
                'text' => 'Hello world',
                'language' => 'en',
            ]), ['response_headers' => ['content-type' => 'application/json']]);
        });

        $result = $this->makeProvider(httpClient: $client)->transcribe($audioFile, [
            'model' => 'voxtral-mini-latest',
            'language' => 'en',
        ]);

        $this->assertSame('Hello world', $result['text']);
        $this->assertSame('en', $result['language']);

        $this->assertSame('POST', $captured['method']);
        $this->assertSame('https://api.mistral.ai/v1/audio/transcriptions', $captured['url']);
        $this->assertContains('Authorization: Bearer test-key', $captured['opts']['headers']);
    }

    public function testTranscribeWrapsHttpErrors(): void
    {
        $audioFile = $this->tempDir.'/sample.mp3';
        file_put_contents($audioFile, 'FAKEAUDIO');

        $client = new MockHttpClient(static fn () => new MockResponse('nope', ['http_code' => 500]));

        try {
            $this->makeProvider(httpClient: $client)->transcribe($audioFile);
            $this->fail('Expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertStringContainsString('HTTP 500', $e->getMessage());
        }
    }

    // ==================== TEXT TO SPEECH ====================

    public function testSynthesizeWritesDecodedAudioAndForwardsBody(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'body' => json_decode($opts['body'], true), 'headers' => $opts['headers']];

            return new MockResponse(json_encode([
                'audio_data' => base64_encode('AUDIO-BYTES'),
            ]), ['response_headers' => ['content-type' => 'application/json']]);
        });

        $filename = $this->makeProvider(httpClient: $client)->synthesize('Hello there', [
            'model' => 'voxtral-mini-tts-2603',
            'voice' => 'my-voice',
        ]);

        $this->assertStringEndsWith('.mp3', $filename);
        $this->assertFileExists($this->tempDir.'/'.$filename);
        $this->assertSame('AUDIO-BYTES', file_get_contents($this->tempDir.'/'.$filename));

        $this->assertSame('https://api.mistral.ai/v1/audio/speech', $captured['url']);
        $this->assertSame('voxtral-mini-tts-2603', $captured['body']['model']);
        $this->assertSame('Hello there', $captured['body']['input']);
        $this->assertSame('mp3', $captured['body']['response_format']);
        $this->assertSame('my-voice', $captured['body']['voice_id']);
        $this->assertContains('Authorization: Bearer test-key', $captured['headers']);
    }

    public function testSynthesizeResolvesDefaultVoiceWhenNoneSupplied(): void
    {
        $speechBody = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$speechBody): MockResponse {
            if (str_contains($url, '/audio/voices')) {
                // The default-voice resolver hits the preset catalog first.
                $this->assertStringContainsString('type=preset', $url);

                return new MockResponse(json_encode([
                    'voices' => [
                        ['slug' => 'fr_marie_neutral', 'languages' => ['fr_fr']],
                        ['slug' => 'en_emma_neutral', 'languages' => ['en_us']],
                    ],
                ]), ['response_headers' => ['content-type' => 'application/json']]);
            }

            $speechBody = json_decode($opts['body'], true);

            return new MockResponse(json_encode([
                'audio_data' => base64_encode('AUDIO-BYTES'),
            ]), ['response_headers' => ['content-type' => 'application/json']]);
        });

        $filename = $this->makeProvider(httpClient: $client)->synthesize('Hello there', [
            'language' => 'en',
        ]);

        $this->assertStringEndsWith('.mp3', $filename);
        // Must send a voice (language-matched preset) — never an empty body that
        // the hosted endpoint rejects with "Either ref_audio or voice must be provided."
        $this->assertSame('en_emma_neutral', $speechBody['voice_id']);
        $this->assertArrayNotHasKey('ref_audio', $speechBody);
    }

    public function testSynthesizeFallsBackToDefaultVoiceWhenCatalogUnavailable(): void
    {
        $speechBody = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$speechBody): MockResponse {
            if (str_contains($url, '/audio/voices')) {
                return new MockResponse('boom', ['http_code' => 500]);
            }

            $speechBody = json_decode($opts['body'], true);

            return new MockResponse(json_encode([
                'audio_data' => base64_encode('AUDIO-BYTES'),
            ]), ['response_headers' => ['content-type' => 'application/json']]);
        });

        $this->makeProvider(httpClient: $client)->synthesize('Hello there');

        $this->assertNotEmpty($speechBody['voice_id']);
    }

    public function testSynthesizeThrowsWhenNoAudioReturned(): void
    {
        $client = new MockHttpClient(static fn () => new MockResponse(json_encode(['audio_data' => '']), [
            'response_headers' => ['content-type' => 'application/json'],
        ]));

        $this->expectExceptionMessageContains('no audio data');
        $this->makeProvider(httpClient: $client)->synthesize('hi');
    }

    public function testSynthesizeStreamYieldsDecodedAudioFragments(): void
    {
        $chunks = [
            'data: {"audio_data":"'.base64_encode('AA').'"}'."\n",
            'data: {"audio_data":"'.base64_encode('BB').'"}'."\n"
                .'data: {"type":"speech.audio.done"}'."\n"
                .'data: [DONE]'."\n",
        ];

        $client = new MockHttpClient(static fn () => new MockResponse($chunks, [
            'response_headers' => ['content-type' => 'text/event-stream'],
        ]));

        $out = '';
        foreach ($this->makeProvider(httpClient: $client)->synthesizeStream('hi', ['model' => 'voxtral-mini-tts-2603']) as $fragment) {
            $out .= $fragment;
        }

        $this->assertSame('AABB', $out);
    }

    public function testGetStreamContentTypeMapsFormats(): void
    {
        $provider = $this->makeProvider();

        $this->assertSame('audio/mpeg', $provider->getStreamContentType());
        $this->assertSame('audio/mpeg', $provider->getStreamContentType(['format' => 'mp3']));
        $this->assertSame('audio/ogg', $provider->getStreamContentType(['format' => 'opus']));
        $this->assertSame('audio/wav', $provider->getStreamContentType(['format' => 'pcm']));
        $this->assertSame('audio/flac', $provider->getStreamContentType(['format' => 'flac']));
    }

    public function testSupportsStreaming(): void
    {
        $this->assertTrue($this->makeProvider()->supportsStreaming());
    }

    // ==================== VOICES ====================

    public function testGetVoicesMapsApiResponse(): void
    {
        $client = new MockHttpClient(static fn () => new MockResponse(json_encode([
            'voices' => [
                ['id' => 'aria', 'name' => 'Aria', 'description' => 'Warm'],
                ['voice_id' => 'leo', 'name' => 'Leo'],
            ],
        ]), ['response_headers' => ['content-type' => 'application/json']]));

        $voices = $this->makeProvider(httpClient: $client)->getVoices();

        $this->assertCount(2, $voices);
        $this->assertSame('aria', $voices[0]['id']);
        $this->assertSame('Aria', $voices[0]['name']);
        $this->assertSame('leo', $voices[1]['id']);
    }

    public function testGetVoicesReturnsEmptyWithoutApiKey(): void
    {
        $this->assertSame([], $this->makeProvider(apiKey: null)->getVoices());
    }

    public function testGetVoicesReturnsEmptyOnError(): void
    {
        $client = new MockHttpClient(static fn () => new MockResponse('boom', ['http_code' => 500]));

        $this->assertSame([], $this->makeProvider(httpClient: $client)->getVoices());
    }

    // ==================== HELPERS ====================

    private function makeProvider(
        ?HttpClientInterface $httpClient = null,
        ?string $apiKey = self::API_KEY,
    ): MistralProvider {
        return new MistralProvider(
            $httpClient ?? new MockHttpClient(),
            new NullLogger(),
            $apiKey,
            $this->tempDir,
        );
    }

    private function expectExceptionMessageContains(string $needle): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($needle, '/').'/');
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $options
     *
     * @return array<string, mixed>
     */
    private function buildChatOptions(array $messages, array $options, bool $stream): array
    {
        $provider = $this->makeProvider();

        return (new \ReflectionClass($provider))->getMethod('buildChatOptions')->invoke($provider, $messages, $options, $stream);
    }
}
