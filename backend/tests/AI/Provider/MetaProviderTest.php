<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Provider\MetaProvider;
use App\AI\StructuredOutput\StructuredOutputSchema;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Unit tests for MetaProvider.
 *
 * Chat/vision run through the openai-php client built internally, so only
 * metadata and preconditions (missing model / missing API key) are asserted
 * here — matching the TrustedTokens unit-test pattern.
 */
class MetaProviderTest extends TestCase
{
    public function testMetadata(): void
    {
        $provider = $this->makeProvider();

        $this->assertSame('meta', $provider->getName());
        $this->assertSame('Meta', $provider->getDisplayName());
        $this->assertTrue($provider->isAvailable());
        $this->assertStringContainsString('Meta', $provider->getDescription());
    }

    public function testCapabilities(): void
    {
        $capabilities = $this->makeProvider()->getCapabilities();

        $this->assertContains('chat', $capabilities);
        $this->assertContains('vision', $capabilities);
        $this->assertContains('image_generation', $capabilities);
        $this->assertContains('speech_to_text', $capabilities);
    }

    public function testDefaultModels(): void
    {
        $defaults = $this->makeProvider()->getDefaultModels();

        $this->assertSame('muse-spark-1.3', $defaults['chat']);
        $this->assertSame('muse-spark-1.3', $defaults['vision']);
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
        $vars = $this->makeProvider()->getRequiredEnvVars();

        $this->assertArrayHasKey('META_API_KEY', $vars);
        $this->assertTrue($vars['META_API_KEY']['required']);
        $this->assertStringContainsString('dev.meta.ai', $vars['META_API_KEY']['hint']);
    }

    public function testChatRequiresModel(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Model must be specified');

        $this->makeProvider()->chat([['role' => 'user', 'content' => 'hi']], []);
    }

    public function testChatRequiresApiKey(): void
    {
        $this->expectException(ProviderException::class);

        $this->makeProvider(apiKey: null)->chat(
            [['role' => 'user', 'content' => 'hi']],
            ['model' => 'muse-spark-1.3'],
        );
    }

    public function testChatOptionsMergeStructuredOutputAsJsonSchema(): void
    {
        $request = $this->buildChatOptions([], [
            'model' => 'muse-spark-1.3',
            'structured_output' => new StructuredOutputSchema('sort_result', ['type' => 'object']),
        ], false);

        $this->assertSame('json_schema', $request['response_format']['type']);
        $this->assertSame('sort_result', $request['response_format']['json_schema']['name']);
        $this->assertSame(['type' => 'object'], $request['response_format']['json_schema']['schema']);
    }

    public function testThinkingOffSendsMinimalBecauseNoneIsRejected(): void
    {
        $request = $this->buildChatOptions([], [
            'model' => 'muse-spark-1.3',
            'reasoning' => false,
        ], false);

        $this->assertSame('minimal', $request['reasoning_effort']);
    }

    public function testThinkingOnUsesTheCatalogDefault(): void
    {
        $request = $this->buildChatOptions([], [
            'model' => 'muse-spark-1.3',
            'reasoning' => true,
            'modelConfig' => ['reasoning_effort_default' => 'high'],
        ], false);

        $this->assertSame('high', $request['reasoning_effort']);
    }

    public function testExplicitNoneIsNotSent(): void
    {
        $request = $this->buildChatOptions([], [
            'model' => 'muse-spark-1.3',
            'reasoning_effort' => 'none',
        ], false);

        $this->assertArrayNotHasKey('reasoning_effort', $request);
    }

    public function testReasoningEffortIsSkippedWhenTheRowHasNoReasoningFeature(): void
    {
        $request = $this->buildChatOptions([], [
            'model' => 'muse-spark-1.3',
            'reasoning' => true,
            'modelFeatures' => ['vision'],
        ], false);

        $this->assertArrayNotHasKey('reasoning_effort', $request);
    }

    public function testGenerateImagePostsToTheGenerationsEndpoint(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? null];

            return new MockResponse((string) json_encode([
                'data' => [['b64_json' => base64_encode('PNG')]],
                'output_format' => 'png',
            ]));
        });

        $images = $this->makeProvider(httpClient: $client)->generateImage('a red fox', [
            'model' => 'muse-image-1.0',
            'size' => '1024x1024',
        ]);

        $this->assertSame('POST', $captured['method']);
        $this->assertStringEndsWith('/images/generations', $captured['url']);
        $body = json_decode((string) $captured['body'], true);
        $this->assertSame('muse-image-1.0', $body['model']);
        $this->assertSame('b64_json', $body['response_format']);
        $this->assertSame('png', $body['output_format']);
        $this->assertSame('1024x1024', $body['size']);
        $this->assertArrayNotHasKey('images', $body);
        $this->assertStringStartsWith('data:image/png;base64,', $images[0]['url']);
    }

    public function testGenerateImageWithAReferenceUsesTheEditsEndpoint(): void
    {
        $png = tempnam(sys_get_temp_dir(), 'meta-img');
        $this->assertNotFalse($png);
        file_put_contents($png, 'png-bytes');

        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['url' => $url, 'body' => $options['body'] ?? null];

            return new MockResponse((string) json_encode([
                'data' => [['b64_json' => base64_encode('PNG')]],
                'output_format' => 'png',
            ]));
        });

        try {
            $this->makeProvider(httpClient: $client)->generateImage('add a red hat', [
                'model' => 'muse-image-1.0',
                'images' => [$png],
            ]);
        } finally {
            unlink($png);
        }

        $this->assertStringEndsWith('/images/edits', $captured['url']);
        $body = json_decode((string) $captured['body'], true);
        $this->assertSame('add a red hat', $body['prompt']);
        $this->assertStringStartsWith('data:', $body['images'][0]['image_url']);
    }

    public function testTranscribeSendsWavAndBillsWholeSeconds(): void
    {
        $wav = $this->writeWav(16000, 16000);
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $raw = self::requestBody($options['body'] ?? '');
            $captured = ['url' => $url, 'raw' => $raw];

            return new MockResponse((string) json_encode([
                'transcript' => 'How is the weather?',
                'audioDurationMs' => 8240,
                'turns' => [],
            ]));
        });

        try {
            $result = $this->makeProvider(httpClient: $client)->transcribe($wav, [
                'model' => 'muse-voice-transcribe-1.0',
                'language' => 'de-DE',
            ]);
        } finally {
            unlink($wav);
        }

        $this->assertStringEndsWith('/asr/transcribe', $captured['url']);
        $this->assertStringContainsString('"audioEncoding":"WAV"', $captured['raw']);
        $this->assertStringContainsString('"mode":"PUSH_TO_TALK"', $captured['raw']);
        $this->assertStringContainsString('"languageBias":["German"]', $captured['raw']);
        $this->assertStringContainsString('audio/wav', $captured['raw']);
        $this->assertSame('How is the weather?', $result['text']);
        $this->assertSame(8.0, $result['duration']);
    }

    public function testTranscribeRejectsAudioLongerThanTenMinutes(): void
    {
        $wav = $this->writeWav(16000, 32000 * 601);

        try {
            $this->expectException(ProviderException::class);
            $this->expectExceptionMessage('10 minutes');
            $this->makeProvider(httpClient: new MockHttpClient())->transcribe($wav);
        } finally {
            unlink($wav);
        }
    }

    public function testMaskedEditAndTranslationAreRefused(): void
    {
        $provider = $this->makeProvider();

        try {
            $provider->editImage('/tmp/a.png', '/tmp/mask.png', 'change it');
            $this->fail('Masked editing should be refused.');
        } catch (ProviderException $e) {
            $this->assertStringContainsString('mask', $e->getMessage());
        }

        $this->expectException(ProviderException::class);
        $provider->translateAudio('/tmp/a.wav', 'en');
    }

    private static function requestBody(mixed $body): string
    {
        if (is_string($body)) {
            return $body;
        }

        if ($body instanceof \Closure) {
            $raw = '';
            while ('' !== $chunk = $body(65536)) {
                if (!is_string($chunk)) {
                    break;
                }
                $raw .= $chunk;
            }

            return $raw;
        }

        if (is_iterable($body)) {
            return implode('', iterator_to_array($body));
        }

        return '';
    }

    private function makeProvider(?string $apiKey = 'test-key', ?MockHttpClient $httpClient = null): MetaProvider
    {
        return new MetaProvider(new NullLogger(), $apiKey, httpClient: $httpClient ?? new MockHttpClient());
    }

    /**
     * Minimal mono 16-bit PCM WAV. The data chunk size can exceed the bytes
     * written, which is how the 10-minute guard is tested without a huge file.
     */
    private function writeWav(int $sampleRate, int $dataBytes): string
    {
        $header = 'RIFF'.pack('V', 36 + $dataBytes).'WAVE';
        $header .= 'fmt '.pack('V', 16);
        $header .= pack('v', 1);
        $header .= pack('v', 1);
        $header .= pack('V', $sampleRate);
        $header .= pack('V', $sampleRate * 2);
        $header .= pack('v', 2);
        $header .= pack('v', 16);
        $header .= 'data'.pack('V', $dataBytes);
        $path = tempnam(sys_get_temp_dir(), 'meta-wav');
        $this->assertNotFalse($path);
        file_put_contents($path, $header);

        return $path;
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
