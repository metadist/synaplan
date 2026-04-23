<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Provider\HuggingFaceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Unit tests for HuggingFaceProvider.
 *
 * Most tests use {@see MockHttpClient} so we exercise real request building
 * (URL, headers, JSON body) without hitting the network.
 */
class HuggingFaceProviderTest extends TestCase
{
    private const API_KEY = 'test-key';

    // ==================== METADATA ====================

    public function testCapabilities(): void
    {
        $provider = $this->makeProvider();

        $capabilities = $provider->getCapabilities();

        $this->assertContains('chat', $capabilities);
        $this->assertContains('vision', $capabilities);
        $this->assertContains('embedding', $capabilities);
        $this->assertContains('image_generation', $capabilities);
        $this->assertContains('video_generation', $capabilities);
    }

    public function testMetadata(): void
    {
        $provider = $this->makeProvider();

        $this->assertSame('huggingface', $provider->getName());
        $this->assertSame('HuggingFace', $provider->getDisplayName());
        $this->assertStringContainsString('Unified API', $provider->getDescription());
        $this->assertStringContainsString('vision', $provider->getDescription());
        $this->assertTrue($provider->isAvailable());
    }

    public function testStatusReportsHealthyWhenApiKeyConfigured(): void
    {
        $status = $this->makeProvider()->getStatus();

        $this->assertTrue($status['healthy']);
        $this->assertArrayNotHasKey('latency_ms', $status, 'Status must not advertise fake latency metrics');
        $this->assertArrayNotHasKey('error_rate', $status);
    }

    public function testProviderUnavailableWithoutApiKey(): void
    {
        $provider = $this->makeProvider(apiKey: null);

        $this->assertFalse($provider->isAvailable());
        $status = $provider->getStatus();
        $this->assertFalse($status['healthy']);
        $this->assertStringContainsString('not configured', $status['error']);
    }

    public function testRequiredEnvVarsExposeTokenUrl(): void
    {
        $envVars = $this->makeProvider()->getRequiredEnvVars();

        $this->assertArrayHasKey('HUGGINGFACE_API_KEY', $envVars);
        $this->assertTrue($envVars['HUGGINGFACE_API_KEY']['required']);
        $this->assertStringContainsString('huggingface.co', $envVars['HUGGINGFACE_API_KEY']['hint']);
    }

    public function testGetDimensionsKnownAndUnknown(): void
    {
        $provider = $this->makeProvider();

        $this->assertSame(1024, $provider->getDimensions('BAAI/bge-m3'));
        $this->assertSame(384, $provider->getDimensions('sentence-transformers/all-MiniLM-L6-v2'));
        $this->assertSame(768, $provider->getDimensions('some/unknown-model'));
    }

    // ==================== PRECONDITION TESTS ====================

    public function testChatThrowsExceptionWithoutApiKey(): void
    {
        $this->expectExceptionMessageContains('HUGGINGFACE_API_KEY');
        $this->makeProvider(apiKey: null)->chat([['role' => 'user', 'content' => 'hi']], ['model' => 'm']);
    }

    public function testChatThrowsExceptionWithoutModel(): void
    {
        $this->expectExceptionMessageContains('Model must be specified');
        $this->makeProvider()->chat([['role' => 'user', 'content' => 'hi']]);
    }

    public function testChatThrowsExceptionWithEmptyMessages(): void
    {
        $this->expectExceptionMessageContains('must not be empty');
        $this->makeProvider()->chat([], ['model' => 'm']);
    }

    public function testChatStreamThrowsExceptionWithEmptyMessages(): void
    {
        $this->expectExceptionMessageContains('must not be empty');
        $this->makeProvider()->chatStream([], static fn () => null, ['model' => 'm']);
    }

    public function testEmbedThrowsExceptionWithoutApiKey(): void
    {
        $this->expectExceptionMessageContains('HUGGINGFACE_API_KEY');
        $this->makeProvider(apiKey: null)->embed('hi', ['model' => 'm']);
    }

    public function testEmbedBatchThrowsExceptionWithoutModel(): void
    {
        $this->expectExceptionMessageContains('Model must be specified');
        $this->makeProvider()->embedBatch(['hi']);
    }

    public function testGenerateImageThrowsExceptionWithoutApiKey(): void
    {
        $this->expectExceptionMessageContains('HUGGINGFACE_API_KEY');
        $this->makeProvider(apiKey: null)->generateImage('cat', ['model' => 'm']);
    }

    public function testGenerateVideoThrowsExceptionWithoutApiKey(): void
    {
        $this->expectExceptionMessageContains('HUGGINGFACE_API_KEY');
        $this->makeProvider(apiKey: null)->generateVideo('cat', ['model' => 'm']);
    }

    public function testEditImageThrowsExceptionWithoutApiKey(): void
    {
        $this->expectExceptionMessageContains('HUGGINGFACE_API_KEY');
        $this->makeProvider(apiKey: null)->editImage('img.png', 'mask.png', 'edit');
    }

    public function testExplainImageThrowsExceptionWithoutApiKey(): void
    {
        $this->expectExceptionMessageContains('HUGGINGFACE_API_KEY');
        $this->makeProvider(apiKey: null)->explainImage('img.png', 'desc', ['model' => 'm']);
    }

    public function testCompareImagesThrowsExceptionWithoutApiKey(): void
    {
        $this->expectExceptionMessageContains('HUGGINGFACE_API_KEY');
        $this->makeProvider(apiKey: null)->compareImages('a.png', 'b.png');
    }

    public function testCreateVariationsAlwaysThrows(): void
    {
        $this->expectExceptionMessageContains('not supported');
        $this->makeProvider()->createVariations('img.png');
    }

    // ==================== CHAT REQUEST BUILDING ====================

    public function testChatPostsToOpenAiCompatibleEndpointAndForwardsAllOptions(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'opts' => $opts];

            return new MockResponse(json_encode([
                'choices' => [['message' => ['content' => 'pong']]],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3, 'total_tokens' => 8],
            ]), ['response_headers' => ['content-type' => 'application/json']]);
        });

        $result = $this->makeProvider(httpClient: $client)->chat(
            [['role' => 'user', 'content' => 'ping']],
            [
                'model' => 'moonshotai/Kimi-K2.6',
                'temperature' => 0.5,
                'top_p' => 0.9,
                'max_tokens' => 256,
                'stop' => ['END'],
                'seed' => 42,
                'presence_penalty' => 0.1,
                'frequency_penalty' => 0.2,
            ],
        );

        $this->assertSame('pong', $result['content']);
        $this->assertSame(5, $result['usage']['prompt_tokens']);
        $this->assertSame(8, $result['usage']['total_tokens']);
        $this->assertSame(0, $result['usage']['cached_tokens']);

        $this->assertSame('POST', $captured['method']);
        $this->assertSame('https://router.huggingface.co/v1/chat/completions', $captured['url']);

        $body = json_decode($captured['opts']['body'], true);
        $this->assertSame('moonshotai/Kimi-K2.6', $body['model']);
        $this->assertFalse($body['stream']);
        $this->assertSame(0.5, $body['temperature']);
        $this->assertSame(0.9, $body['top_p']);
        $this->assertSame(256, $body['max_tokens']);
        $this->assertSame(['END'], $body['stop']);
        $this->assertSame(42, $body['seed']);
        $this->assertSame(0.1, $body['presence_penalty']);
        $this->assertSame(0.2, $body['frequency_penalty']);
        $this->assertContains('Authorization: Bearer test-key', $captured['opts']['headers']);
    }

    public function testChatAppliesProviderStrategySuffix(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured = json_decode($opts['body'], true);

            return new MockResponse(json_encode(['choices' => [['message' => ['content' => '']]]]));
        });

        $this->makeProvider(httpClient: $client)->chat(
            [['role' => 'user', 'content' => 'hi']],
            ['model' => 'moonshotai/Kimi-K2.6', 'provider_strategy' => 'fastest'],
        );

        $this->assertSame('moonshotai/Kimi-K2.6:fastest', $captured['model']);
    }

    public function testChatWrapsHttpErrorsAsProviderException(): void
    {
        $client = new MockHttpClient(static fn () => new MockResponse('err', ['http_code' => 500]));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageContains('HuggingFace chat error');

        $this->makeProvider(httpClient: $client)->chat(
            [['role' => 'user', 'content' => 'hi']],
            ['model' => 'moonshotai/Kimi-K2.6'],
        );
    }

    public function testChatPropagates402AsBillingProviderException(): void
    {
        $client = new MockHttpClient(static fn () => new MockResponse('payment required', ['http_code' => 402]));

        try {
            $this->makeProvider(httpClient: $client)->chat(
                [['role' => 'user', 'content' => 'hi']],
                ['model' => 'moonshotai/Kimi-K2.6'],
            );
            $this->fail('Expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertStringContainsString('prepaid credits', $e->getMessage());
            $this->assertStringContainsString('huggingface.co/settings/billing', $e->getMessage());
        }
    }

    // ==================== STREAM PARSING ====================

    public function testChatStreamReassemblesPartialSseLinesAndEmitsFinishSignal(): void
    {
        // Two HTTP chunks; the first ends mid-line on purpose to validate the line buffer.
        $chunks = [
            "data: {\"choices\":[{\"delta\":{\"content\":\"Hel\"}}]}\ndata: {\"choi",
            "ces\":[{\"delta\":{\"content\":\"lo\"},\"finish_reason\":null}]}\n"
                ."data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"prompt_tokens\":2,\"completion_tokens\":1,\"total_tokens\":3}}\n"
                .'data: [DONE]'."\n",
        ];

        $client = new MockHttpClient(static fn () => new MockResponse($chunks, [
            'response_headers' => ['content-type' => 'text/event-stream'],
        ]));

        $received = [];
        $finish = null;
        $callback = static function (mixed $chunk) use (&$received, &$finish): void {
            if (is_array($chunk) && 'finish' === ($chunk['type'] ?? null)) {
                $finish = $chunk;

                return;
            }
            $received[] = $chunk;
        };

        $result = $this->makeProvider(httpClient: $client)->chatStream(
            [['role' => 'user', 'content' => 'hi']],
            $callback,
            ['model' => 'moonshotai/Kimi-K2.6'],
        );

        $this->assertSame(['Hel', 'lo'], $received, 'Both fragments must arrive intact across chunk boundaries');
        $this->assertNotNull($finish, 'A finish signal must be emitted at the end of the stream');
        $this->assertSame('stop', $finish['finish_reason']);
        $this->assertSame(3, $result['usage']['total_tokens']);
    }

    /**
     * Regression: a 402 during streaming must surface as a billing
     * ProviderException BEFORE we touch the SSE body. Otherwise the empty
     * body would yield a phantom successful "finish" callback and silently
     * mask the real billing error from the caller.
     */
    public function testChatStream402PreservesBillingMessageAndDoesNotEmitFinish(): void
    {
        $client = new MockHttpClient(static fn () => new MockResponse('payment required', ['http_code' => 402]));

        $invocations = [];
        $callback = static function (mixed $chunk) use (&$invocations): void {
            $invocations[] = $chunk;
        };

        try {
            $this->makeProvider(httpClient: $client)->chatStream(
                [['role' => 'user', 'content' => 'hi']],
                $callback,
                ['model' => 'moonshotai/Kimi-K2.6'],
            );
            $this->fail('Expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertStringContainsString('prepaid credits', $e->getMessage());
            $this->assertStringContainsString('huggingface.co/settings/billing', $e->getMessage());
        }

        $this->assertSame([], $invocations, 'Callback must NOT receive any chunks or a phantom finish on 402');
    }

    /**
     * Regression: any non-2xx (5xx, 401, etc.) during streaming must surface
     * as a ProviderException with the HTTP status, not be swallowed as an
     * empty stream that emits a phantom finish event.
     */
    public function testChatStream500PreservesHttpStatusAndDoesNotEmitFinish(): void
    {
        $client = new MockHttpClient(static fn () => new MockResponse('upstream exploded', ['http_code' => 500]));

        $invocations = [];
        $callback = static function (mixed $chunk) use (&$invocations): void {
            $invocations[] = $chunk;
        };

        try {
            $this->makeProvider(httpClient: $client)->chatStream(
                [['role' => 'user', 'content' => 'hi']],
                $callback,
                ['model' => 'moonshotai/Kimi-K2.6'],
            );
            $this->fail('Expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertStringContainsString('HTTP 500', $e->getMessage());
            $this->assertStringContainsString('upstream exploded', $e->getMessage());
        }

        $this->assertSame([], $invocations, 'Callback must NOT receive any chunks or a phantom finish on 5xx');
    }

    // ==================== EMBEDDING URL ROUTING ====================

    public function testEmbedRoutesThroughDefaultSubProviderAndIncludesNormalize(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured = ['url' => $url, 'body' => json_decode($opts['body'], true)];

            return new MockResponse(json_encode([[0.1, 0.2, 0.3]]));
        });

        $this->makeProvider(httpClient: $client)->embed('hello world', ['model' => 'BAAI/bge-m3']);

        $this->assertSame('https://router.huggingface.co/hf-inference/models/BAAI/bge-m3', $captured['url']);
        $this->assertSame('hello world', $captured['body']['inputs']);
        $this->assertTrue($captured['body']['normalize']);
    }

    public function testEmbedTreatsExplicitHuggingFaceProviderAsDefault(): void
    {
        $captured = '';
        $client = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = $url;

            return new MockResponse(json_encode([[0.1]]));
        });

        $this->makeProvider(httpClient: $client)
            ->embed('hi', ['model' => 'BAAI/bge-m3', 'provider' => 'HuggingFace']);

        $this->assertSame('https://router.huggingface.co/hf-inference/models/BAAI/bge-m3', $captured);
    }

    public function testEmbedHonoursCustomSubProvider(): void
    {
        $captured = '';
        $client = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = $url;

            return new MockResponse(json_encode([[0.1]]));
        });

        $this->makeProvider(httpClient: $client)
            ->embed('hi', ['model' => 'BAAI/bge-m3', 'provider' => 'sambanova']);

        $this->assertSame('https://router.huggingface.co/sambanova/models/BAAI/bge-m3', $captured);
    }

    // ==================== IMAGE GENERATION ====================

    public function testGenerateImageReturnsDataUrlAndForwardsParameters(): void
    {
        $capturedBody = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$capturedBody): MockResponse {
            $capturedBody = json_decode($opts['body'], true);

            return new MockResponse('PNGDATA', [
                'response_headers' => ['content-type' => 'image/png'],
            ]);
        });

        $result = $this->makeProvider(httpClient: $client)->generateImage('a cat', [
            'model' => 'black-forest-labs/FLUX.1-schnell',
            'width' => 512,
            'height' => 256,
            'guidance_scale' => 4.5,
            'seed' => 7,
            'negative_prompt' => 'blurry',
        ]);

        $this->assertSame('a cat', $capturedBody['inputs']);
        $this->assertSame(512, $capturedBody['parameters']['width']);
        $this->assertSame(256, $capturedBody['parameters']['height']);
        $this->assertSame(4.5, $capturedBody['parameters']['guidance_scale']);
        $this->assertSame(7, $capturedBody['parameters']['seed']);
        $this->assertSame('blurry', $capturedBody['parameters']['negative_prompt']);

        $this->assertCount(1, $result);
        $this->assertStringStartsWith('data:image/png;base64,', $result[0]['url']);
        $this->assertSame('a cat', $result[0]['revised_prompt']);
    }

    public function testGenerateImage402PreservesBillingMessage(): void
    {
        $client = new MockHttpClient(static fn () => new MockResponse('payment required', ['http_code' => 402]));

        try {
            $this->makeProvider(httpClient: $client)->generateImage('cat', ['model' => 'm']);
            $this->fail('Expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertStringContainsString('prepaid credits', $e->getMessage());
            $this->assertStringContainsString('huggingface.co/settings/billing', $e->getMessage());
        }
    }

    // ==================== HELPERS ====================

    private function makeProvider(
        ?HttpClientInterface $httpClient = null,
        ?string $apiKey = self::API_KEY,
    ): HuggingFaceProvider {
        return new HuggingFaceProvider(
            $httpClient ?? $this->createMock(HttpClientInterface::class),
            new NullLogger(),
            $apiKey,
        );
    }

    private function expectExceptionMessageContains(string $needle): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($needle, '/').'/');
    }
}
