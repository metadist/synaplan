<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Provider\XaiProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Unit tests for XaiProvider.
 *
 * Chat and vision run through the openai-php client (built inside the
 * constructor), so their request shaping and usage/stream mapping are asserted
 * on the private helpers. The Grok Imagine endpoints use Symfony's HttpClient
 * and are exercised end to end with {@see MockHttpClient}.
 */
class XaiProviderTest extends TestCase
{
    private const API_KEY = 'test-key';

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/xai_test_'.uniqid();
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

        $this->assertSame('xai', $provider->getName());
        $this->assertSame('xAI', $provider->getDisplayName());
        $this->assertTrue($provider->isAvailable());
    }

    public function testCapabilities(): void
    {
        $capabilities = $this->makeProvider()->getCapabilities();

        $this->assertContains('chat', $capabilities);
        $this->assertContains('vision', $capabilities);
        $this->assertContains('image_generation', $capabilities);
        $this->assertContains('video_generation', $capabilities);
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

        $this->assertArrayHasKey('XAI_API_KEY', $envVars);
        $this->assertTrue($envVars['XAI_API_KEY']['required']);
        $this->assertStringContainsString('console.x.ai', $envVars['XAI_API_KEY']['hint']);
    }

    // ==================== PRECONDITIONS ====================

    public function testChatThrowsWithoutModel(): void
    {
        $this->expectExceptionMessageContains('Model must be specified');
        $this->makeProvider()->chat([['role' => 'user', 'content' => 'hi']]);
    }

    public function testChatThrowsWithoutApiKey(): void
    {
        $this->expectExceptionMessageContains('XAI_API_KEY');
        $this->makeProvider(apiKey: null)->chat([['role' => 'user', 'content' => 'hi']], ['model' => 'grok-4.3']);
    }

    public function testExplainImageThrowsWithoutApiKey(): void
    {
        $this->expectExceptionMessageContains('XAI_API_KEY');
        $this->makeProvider(apiKey: null)->explainImage('image.png');
    }

    public function testGenerateImageThrowsWithoutApiKey(): void
    {
        $this->expectExceptionMessageContains('XAI_API_KEY');
        $this->makeProvider(apiKey: null)->generateImage('a red cube');
    }

    public function testStartVideoOperationThrowsWithoutApiKey(): void
    {
        $this->expectExceptionMessageContains('XAI_API_KEY');
        $this->makeProvider(apiKey: null)->startVideoOperation('a red cube spinning');
    }

    public function testExplainImageThrowsWhenFileMissing(): void
    {
        $this->expectExceptionMessageContains('Image file not found');
        $this->makeProvider()->explainImage('does-not-exist.png');
    }

    public function testExplainImageRejectsUnsupportedImageType(): void
    {
        $file = $this->tempDir.'/sheet.gif';
        // Minimal valid GIF header so mime_content_type detects image/gif.
        file_put_contents($file, "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x00\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;");

        $this->expectExceptionMessageContains('Unsupported image type for xAI');
        $this->makeProvider()->explainImage($file);
    }

    // ==================== CHAT REQUEST SHAPE ====================

    public function testChatRequestUsesMaxCompletionTokensAndNeverMaxTokens(): void
    {
        $request = $this->buildChatOptions(
            [['role' => 'user', 'content' => 'hi']],
            ['model' => 'grok-4.3', 'max_tokens' => 1234],
            false,
        );

        $this->assertSame(1234, $request['max_completion_tokens']);
        $this->assertArrayNotHasKey('max_tokens', $request);
        $this->assertSame('grok-4.3', $request['model']);
        $this->assertArrayNotHasKey('stream', $request);
    }

    public function testStreamRequestAsksForUsageChunk(): void
    {
        $request = $this->buildChatOptions([], ['model' => 'grok-4.5'], true);

        $this->assertTrue($request['stream']);
        $this->assertSame(['include_usage' => true], $request['stream_options']);
    }

    public function testChatRequestForwardsPromptCacheKey(): void
    {
        $request = $this->buildChatOptions([], ['model' => 'grok-4.3', 'cache_key' => 'thread-42'], false);

        $this->assertSame('thread-42', $request['prompt_cache_key']);
    }

    // ==================== REASONING EFFORT ====================

    public function testReasoningEffortIsOmittedWithoutAnySignal(): void
    {
        $request = $this->buildChatOptions([], ['model' => 'grok-4.3'], false);

        $this->assertArrayNotHasKey('reasoning_effort', $request);
    }

    public function testExplicitReasoningEffortWins(): void
    {
        $request = $this->buildChatOptions([], ['model' => 'grok-4.3', 'reasoning_effort' => 'medium'], false);

        $this->assertSame('medium', $request['reasoning_effort']);
    }

    /**
     * xAI documents `reasoning_effort` for grok-4.3 only, so no signal — not
     * even an explicit one — may leak the parameter onto another model.
     */
    public function testReasoningEffortIsNeverSentForModelsThatDoNotSupportIt(): void
    {
        foreach ([['reasoning' => true], ['reasoning' => false], ['reasoning_effort' => 'high']] as $options) {
            $request = $this->buildChatOptions([], ['model' => 'grok-4.5', ...$options], false);

            $this->assertArrayNotHasKey('reasoning_effort', $request);
        }
    }

    public function testThinkingToggleUsesCatalogDefault(): void
    {
        $request = $this->buildChatOptions([], [
            'model' => 'grok-4.3',
            'reasoning' => true,
            'modelConfig' => ['reasoning_effort_default' => 'medium'],
        ], false);

        $this->assertSame('medium', $request['reasoning_effort']);
    }

    public function testThinkingToggleFallsBackToHighWithoutCatalogValue(): void
    {
        $request = $this->buildChatOptions([], ['model' => 'grok-4.3', 'reasoning' => true], false);

        $this->assertSame('high', $request['reasoning_effort']);
    }

    /**
     * Thinking off must beat xAI's server-side default of `low` on cost, which
     * means switching reasoning off entirely.
     */
    public function testDisabledThinkingTurnsReasoningOff(): void
    {
        $request = $this->buildChatOptions([], ['model' => 'grok-4.3', 'reasoning' => false], false);

        $this->assertSame('none', $request['reasoning_effort']);
    }

    public function testReasoningEffortIsSkippedForModelsWithoutTheFeature(): void
    {
        $request = $this->buildChatOptions([], [
            'model' => 'grok-4.3',
            'reasoning' => true,
            'modelFeatures' => ['vision', 'ocr'],
        ], false);

        $this->assertArrayNotHasKey('reasoning_effort', $request);
    }

    // ==================== USAGE MAPPING ====================

    public function testUsageMappingIncludesCachedTokens(): void
    {
        $usage = $this->invokePrivate('parseUsage', [[
            'prompt_tokens' => 1200,
            'completion_tokens' => 300,
            'total_tokens' => 1500,
            'prompt_tokens_details' => ['cached_tokens' => 800],
            'completion_tokens_details' => ['reasoning_tokens' => 250],
        ]]);

        $this->assertSame([
            'prompt_tokens' => 1200,
            // Reasoning tokens are already part of completion_tokens at xAI and
            // must not be added on top.
            'completion_tokens' => 300,
            'total_tokens' => 1500,
            'cached_tokens' => 800,
            'cache_creation_tokens' => 0,
        ], $usage);
    }

    public function testUsageMappingDefaultsToZerosWhenChunkHasNoUsage(): void
    {
        $usage = $this->invokePrivate('parseUsage', [[]]);

        $this->assertSame(0, $usage['prompt_tokens']);
        $this->assertSame(0, $usage['cached_tokens']);
    }

    // ==================== STREAMING DELTAS ====================

    public function testStreamDeltaEmitsReasoningThenContent(): void
    {
        $events = [];
        $callback = static function ($chunk) use (&$events): void {
            $events[] = $chunk;
        };

        $this->invokePrivate('dispatchStreamDelta', [
            ['choices' => [['delta' => ['reasoning_content' => 'weighing options', 'content' => 'Hello']]]],
            $callback,
        ]);

        $this->assertSame([
            ['type' => 'reasoning', 'content' => 'weighing options'],
            'Hello',
        ], $events);
    }

    public function testStreamDeltaIgnoresEmptyDeltas(): void
    {
        $events = [];
        $callback = static function ($chunk) use (&$events): void {
            $events[] = $chunk;
        };

        $this->invokePrivate('dispatchStreamDelta', [['choices' => [['delta' => []]]], $callback]);
        $this->invokePrivate('dispatchStreamDelta', [['choices' => [['delta' => ['content' => '']]]], $callback]);

        $this->assertSame([], $events);
    }

    // ==================== IMAGE GENERATION ====================

    public function testGenerateImagePostsB64PayloadAndReturnsDataUrl(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'body' => json_decode($opts['body'], true), 'headers' => $opts['headers']];

            return $this->jsonResponse([
                'data' => [
                    ['b64_json' => base64_encode('PNGBYTES'), 'mime_type' => 'image/png'],
                ],
            ]);
        });

        $images = $this->makeProvider(httpClient: $client)->generateImage('a red cube', [
            'model' => 'grok-imagine-image',
            'aspect_ratio' => '16:9',
        ]);

        $this->assertSame('POST', $captured['method']);
        $this->assertSame('https://api.x.ai/v1/images/generations', $captured['url']);
        $this->assertContains('Authorization: Bearer test-key', $captured['headers']);
        $this->assertSame('b64_json', $captured['body']['response_format']);
        $this->assertSame('grok-imagine-image', $captured['body']['model']);
        $this->assertSame('16:9', $captured['body']['aspect_ratio']);
        $this->assertSame(1, $captured['body']['n']);

        $this->assertCount(1, $images);
        $this->assertSame('data:image/png;base64,'.base64_encode('PNGBYTES'), $images[0]['url']);
        $this->assertSame(base64_encode('PNGBYTES'), $images[0]['b64_json']);
        $this->assertNull($images[0]['revised_prompt']);
    }

    public function testGenerateImageFailsLoudlyOnEmptyPayload(): void
    {
        $client = new MockHttpClient(fn () => $this->jsonResponse(['data' => []]));

        $this->expectExceptionMessageContains('returned no images');
        $this->makeProvider(httpClient: $client)->generateImage('a red cube');
    }

    public function testGenerateImageSurfacesOutOfCreditsClearly(): void
    {
        $client = new MockHttpClient(fn () => $this->jsonResponse(['error' => ['message' => 'no funds']], 402));

        $this->expectExceptionMessageContains('out of credits');
        $this->makeProvider(httpClient: $client)->generateImage('a red cube');
    }

    public function testImageVariationsAndEditingAreRejectedWithAReason(): void
    {
        $provider = $this->makeProvider();

        try {
            $provider->createVariations('image.png');
            $this->fail('Expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertStringContainsString('no image-variation endpoint', $e->getMessage());
        }

        try {
            $provider->editImage('image.png', 'mask.png', 'make it blue');
            $this->fail('Expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertStringContainsString('not enabled in Synaplan yet', $e->getMessage());
        }
    }

    // ==================== VIDEO GENERATION ====================

    public function testStartVideoOperationReturnsHandleWithRequestId(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'body' => json_decode($opts['body'], true)];

            return $this->jsonResponse(['request_id' => 'req-123']);
        });

        $operation = $this->makeProvider(httpClient: $client)->startVideoOperation('a red cube spinning', [
            'model' => 'grok-imagine-video',
            'duration' => 4,
            'resolution' => '480p',
            'modelConfig' => [
                'allowed_resolutions' => ['480p', '720p'],
                'default_resolution' => '720p',
                'max_duration' => 15,
            ],
        ]);

        $this->assertSame('POST', $captured['method']);
        $this->assertSame('https://api.x.ai/v1/videos/generations', $captured['url']);
        $this->assertSame(4, $captured['body']['duration']);
        $this->assertSame('480p', $captured['body']['resolution']);

        $this->assertSame('grok-imagine-video', $operation['model']);
        $this->assertSame(4, $operation['duration']);
        $this->assertSame('480p', $operation['resolution']);

        $handle = json_decode($operation['operationName'], true);
        $this->assertSame('req-123', $handle['request_id']);
        $this->assertSame('480p', $handle['resolution']);
    }

    public function testVideoResolutionFallsBackToAPriceableValue(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured = json_decode($opts['body'], true);

            return $this->jsonResponse(['request_id' => 'req-123']);
        });

        // 1080p is not in the catalog's allowed list, so it must not reach xAI —
        // otherwise the render would be billed at a rate the row cannot price.
        $this->makeProvider(httpClient: $client)->startVideoOperation('a red cube', [
            'resolution' => '1080p',
            'duration' => 99,
            'modelConfig' => [
                'allowed_resolutions' => ['480p', '720p'],
                'default_resolution' => '720p',
                'max_duration' => 15,
            ],
        ]);

        $this->assertSame('720p', $captured['resolution']);
        $this->assertSame(15, $captured['duration']);
    }

    public function testStartVideoOperationFailsWhenRequestIdIsMissing(): void
    {
        $client = new MockHttpClient(fn () => $this->jsonResponse(['status' => 'queued']));

        $this->expectExceptionMessageContains('missing request_id');
        $this->makeProvider(httpClient: $client)->startVideoOperation('a red cube');
    }

    public function testPollReportsProgressWhileRendering(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url];

            return $this->jsonResponse(['status' => 'pending', 'progress' => 42]);
        });

        $result = $this->makeProvider(httpClient: $client)->pollVideoOperationOnce($this->videoHandle());

        $this->assertSame('GET', $captured['method']);
        $this->assertSame('https://api.x.ai/v1/videos/req-123', $captured['url']);
        $this->assertFalse($result['done']);
        $this->assertSame(42, $result['percent']);
        $this->assertNull($result['videoUri']);
        $this->assertNull($result['error']);
    }

    public function testPollReturnsMediaUrlWhenDone(): void
    {
        $client = new MockHttpClient(fn () => $this->jsonResponse([
            'status' => 'done',
            'progress' => 100,
            'video' => ['url' => 'https://bucket.x.ai/req-123.mp4', 'respect_moderation' => true],
        ]));

        $result = $this->makeProvider(httpClient: $client)->pollVideoOperationOnce($this->videoHandle());

        $this->assertTrue($result['done']);
        $this->assertSame('https://bucket.x.ai/req-123.mp4', $result['videoUri']);
        $this->assertNull($result['error']);
        $this->assertSame(100, $result['percent']);
    }

    public function testPollTreatsFailureAsTerminalWithMessage(): void
    {
        $client = new MockHttpClient(fn () => $this->jsonResponse([
            'status' => 'failed',
            'error' => ['message' => 'render crashed'],
        ]));

        $result = $this->makeProvider(httpClient: $client)->pollVideoOperationOnce($this->videoHandle());

        $this->assertTrue($result['done']);
        $this->assertNull($result['videoUri']);
        $this->assertSame('render crashed', $result['error']);
    }

    public function testPollTreatsExpiredAsTerminal(): void
    {
        $client = new MockHttpClient(fn () => $this->jsonResponse(['status' => 'expired']));

        $result = $this->makeProvider(httpClient: $client)->pollVideoOperationOnce($this->videoHandle());

        $this->assertTrue($result['done']);
        $this->assertSame('xAI video generation expired', $result['error']);
    }

    public function testPollReportsModerationBlockInsteadOfMissingUrl(): void
    {
        $client = new MockHttpClient(fn () => $this->jsonResponse([
            'status' => 'done',
            'video' => ['url' => '', 'respect_moderation' => false],
        ]));

        $result = $this->makeProvider(httpClient: $client)->pollVideoOperationOnce($this->videoHandle());

        $this->assertTrue($result['done']);
        $this->assertNull($result['videoUri']);
        $this->assertStringContainsString('safety filter', $result['error']);
    }

    public function testPollRejectsAnUnparseableHandle(): void
    {
        $this->expectExceptionMessageContains('Invalid xAI video operation handle');
        $this->makeProvider()->pollVideoOperationOnce('not-json');
    }

    public function testDownloadVideoRawFetchesWithoutAuthHeader(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'headers' => $opts['headers'] ?? []];

            return new MockResponse('MP4BYTES');
        });

        $content = $this->makeProvider(httpClient: $client)->downloadVideoRaw('https://bucket.x.ai/req-123.mp4');

        $this->assertSame('MP4BYTES', $content);
        $this->assertSame('GET', $captured['method']);
        $this->assertNotContains('Authorization: Bearer test-key', $captured['headers']);
    }

    public function testCancelVideoOperationIsANoOp(): void
    {
        $client = new MockHttpClient(function (): MockResponse {
            $this->fail('cancelVideoOperation must not call xAI — there is no cancel endpoint');
        });

        $this->makeProvider(httpClient: $client)->cancelVideoOperation($this->videoHandle());
        $this->makeProvider(httpClient: $client)->cancelVideoOperation('not-json');

        $this->expectNotToPerformAssertions();
    }

    // ==================== HELPERS ====================

    private function makeProvider(
        ?HttpClientInterface $httpClient = null,
        ?string $apiKey = self::API_KEY,
    ): XaiProvider {
        return new XaiProvider(
            $httpClient ?? new MockHttpClient(),
            new NullLogger(),
            $apiKey,
            $this->tempDir,
            0,
        );
    }

    private function videoHandle(): string
    {
        return json_encode([
            'request_id' => 'req-123',
            'model' => 'grok-imagine-video',
            'duration' => 4,
            'resolution' => '480p',
            'started_at' => time(),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(array $payload, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($payload), [
            'http_code' => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $options
     *
     * @return array<string, mixed>
     */
    private function buildChatOptions(array $messages, array $options, bool $stream): array
    {
        return $this->invokePrivate('buildChatOptions', [$messages, $options, $stream]);
    }

    /**
     * @param list<mixed> $args
     */
    private function invokePrivate(string $method, array $args): mixed
    {
        $provider = $this->makeProvider();

        return (new \ReflectionClass($provider))->getMethod($method)->invoke($provider, ...$args);
    }

    private function expectExceptionMessageContains(string $needle): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($needle, '/').'/');
    }
}
