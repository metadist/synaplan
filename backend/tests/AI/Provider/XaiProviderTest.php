<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Provider\XaiProvider;
use App\AI\StructuredOutput\StructuredOutputSchema;
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

        // generateVideo() re-arms the process-wide execution budget while
        // polling. Restore unlimited CLI time so later tests cannot inherit it.
        set_time_limit(0);
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
        $this->assertContains('text_to_speech', $capabilities);
        $this->assertContains('speech_to_text', $capabilities);
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
        $this->makeProvider(apiKey: null)->chat([['role' => 'user', 'content' => 'hi']], ['model' => 'grok-4.5']);
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
            ['model' => 'grok-4.5', 'max_tokens' => 1234],
            false,
        );

        $this->assertSame(1234, $request['max_completion_tokens']);
        $this->assertArrayNotHasKey('max_tokens', $request);
        $this->assertSame('grok-4.5', $request['model']);
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
        $request = $this->buildChatOptions([], ['model' => 'grok-4.5', 'cache_key' => 'thread-42'], false);

        $this->assertSame('thread-42', $request['prompt_cache_key']);
    }

    // ==================== STRUCTURED OUTPUT (Phase 2a) ====================

    public function testChatRequestMergesStructuredOutputAsJsonSchema(): void
    {
        $request = $this->buildChatOptions([], [
            'model' => 'grok-4.5',
            'structured_output' => new StructuredOutputSchema('sort_result', ['type' => 'object']),
        ], false);

        $this->assertSame('json_schema', $request['response_format']['type']);
        $this->assertSame('sort_result', $request['response_format']['json_schema']['name']);
        $this->assertSame(['type' => 'object'], $request['response_format']['json_schema']['schema']);
    }

    public function testChatRequestWithoutStructuredOutputOmitsResponseFormat(): void
    {
        $request = $this->buildChatOptions([], ['model' => 'grok-4.5'], false);

        $this->assertArrayNotHasKey('response_format', $request);
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

    /**
     * `stream_options.include_usage` makes xAI end every stream with a chunk that
     * carries usage and an empty `choices` array. Dereferencing it must stay a
     * no-op rather than emitting a bogus token or tripping a PHP warning.
     */
    public function testStreamDeltaIgnoresChunksWithoutADelta(): void
    {
        $events = [];
        $callback = static function ($chunk) use (&$events): void {
            $events[] = $chunk;
        };

        $this->invokePrivate('dispatchStreamDelta', [
            ['choices' => [], 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 3]],
            $callback,
        ]);
        $this->invokePrivate('dispatchStreamDelta', [['usage' => ['prompt_tokens' => 12]], $callback]);
        $this->invokePrivate('dispatchStreamDelta', [['choices' => [['finish_reason' => 'stop']]], $callback]);
        $this->invokePrivate('dispatchStreamDelta', [['choices' => 'unexpected'], $callback]);

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

    /**
     * The billed price comes from the catalog's `default_resolution`, so the
     * request must carry that exact value instead of relying on xAI's
     * undocumented default.
     */
    public function testGenerateImageSendsTheCatalogResolution(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured = json_decode($opts['body'], true);

            return $this->jsonResponse(['data' => [['b64_json' => base64_encode('X'), 'mime_type' => 'image/png']]]);
        });

        $this->makeProvider(httpClient: $client)->generateImage('a red cube', [
            'model' => 'grok-imagine-image-quality',
            'modelConfig' => [
                'allowed_resolutions' => ['1k', '2k'],
                'default_resolution' => '1k',
            ],
        ]);

        $this->assertSame('1k', $captured['resolution']);
    }

    public function testGenerateImageClampsAnUnpriceableResolution(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured = json_decode($opts['body'], true);

            return $this->jsonResponse(['data' => [['b64_json' => base64_encode('X'), 'mime_type' => 'image/png']]]);
        });

        $this->makeProvider(httpClient: $client)->generateImage('a red cube', [
            'model' => 'grok-imagine-image-quality',
            'resolution' => '4k',
            'modelConfig' => [
                'allowed_resolutions' => ['1k', '2k'],
                'default_resolution' => '1k',
            ],
        ]);

        $this->assertSame('1k', $captured['resolution']);
    }

    public function testGenerateImageFailsLoudlyOnEmptyPayload(): void
    {
        $client = new MockHttpClient(fn () => $this->jsonResponse(['data' => []]));

        $this->expectExceptionMessageContains('returned no images');
        $this->makeProvider(httpClient: $client)->generateImage('a red cube');
    }

    /**
     * A moderated render answers 200 with `respect_moderation: false`, so the
     * user must read "blocked by the safety filter", not "broken payload".
     */
    public function testGenerateImageReportsAModerationBlock(): void
    {
        $topLevel = new MockHttpClient(fn () => $this->jsonResponse([
            'respect_moderation' => false,
            'data' => [],
        ]));
        $perItem = new MockHttpClient(fn () => $this->jsonResponse([
            'data' => [['respect_moderation' => false]],
        ]));

        foreach ([$topLevel, $perItem] as $client) {
            try {
                $this->makeProvider(httpClient: $client)->generateImage('a red cube');
                $this->fail('Expected ProviderException');
            } catch (ProviderException $e) {
                $this->assertStringContainsString('safety filter', $e->getMessage());
            }
        }
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

    /**
     * xAI takes the reference frame as a data URI, which is what lets
     * MediaGenerationHandler hand over the local upload path instead of
     * republishing it at an internet-reachable APP_URL.
     */
    public function testStartVideoOperationInlinesALocalReferenceImage(): void
    {
        $file = $this->tempDir.'/frame.png';
        // Minimal 1x1 PNG so mime_content_type() reports image/png.
        file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='));

        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured = json_decode($opts['body'], true);

            return $this->jsonResponse(['request_id' => 'req-inline']);
        });

        $this->makeProvider(httpClient: $client)->startVideoOperation('animate it', [
            'model' => 'grok-imagine-video-1.5',
            'image_url' => $file,
        ]);

        $this->assertStringStartsWith('data:image/png;base64,', $captured['image']['url']);
    }

    public function testStartVideoOperationForwardsAPublicReferenceUrlUntouched(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured = json_decode($opts['body'], true);

            return $this->jsonResponse(['request_id' => 'req-url']);
        });

        $this->makeProvider(httpClient: $client)->startVideoOperation('animate it', [
            'image_url' => 'https://cdn.example.test/frame.png',
        ]);

        $this->assertSame('https://cdn.example.test/frame.png', $captured['image']['url']);
    }

    /**
     * xAI satisfies an explicit aspect_ratio by stretching the reference frame
     * into it, so forwarding MediaGenerationHandler's 16:9 default distorted
     * every image-to-video render started from a square still.
     */
    public function testStartVideoOperationDropsTheAspectRatioForImageToVideo(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured = json_decode($opts['body'], true);

            return $this->jsonResponse(['request_id' => 'req-i2v']);
        });

        $this->makeProvider(httpClient: $client)->startVideoOperation('animate it', [
            'model' => 'grok-imagine-video-1.5',
            'image_url' => 'https://cdn.example.test/square.png',
            'aspect_ratio' => '16:9',
        ]);

        $this->assertArrayNotHasKey('aspect_ratio', $captured);
        $this->assertSame('https://cdn.example.test/square.png', $captured['image']['url']);
    }

    public function testStartVideoOperationKeepsTheAspectRatioForTextToVideo(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured = json_decode($opts['body'], true);

            return $this->jsonResponse(['request_id' => 'req-t2v']);
        });

        $this->makeProvider(httpClient: $client)->startVideoOperation('a red cube spinning', [
            'aspect_ratio' => '16:9',
        ]);

        $this->assertSame('16:9', $captured['aspect_ratio']);
        $this->assertArrayNotHasKey('image', $captured);
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

    /**
     * A row that prices only 480p/720p but forgets `allowed_resolutions` must not
     * fall through to the API-level list and render an unpriced 1080p.
     */
    public function testVideoResolutionStaysWithinThePricedTiersWithoutAnAllowedList(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured = json_decode($opts['body'], true);

            return $this->jsonResponse(['request_id' => 'req-123']);
        });

        $this->makeProvider(httpClient: $client)->startVideoOperation('a red cube', [
            'resolution' => '1080p',
            'modelConfig' => [
                'default_resolution' => '720p',
                'resolution_prices' => ['480p' => 0.05, '720p' => 0.07],
            ],
        ]);

        $this->assertSame('720p', $captured['resolution']);
    }

    /**
     * The 1.5 row prices 1080p, so the same request must go through there — the
     * clamp exists for billing safety, not to cap quality.
     */
    public function testVideoResolutionAllowsAPricedHighTier(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured = json_decode($opts['body'], true);

            return $this->jsonResponse(['request_id' => 'req-123']);
        });

        $this->makeProvider(httpClient: $client)->startVideoOperation('a red cube', [
            'resolution' => '1080p',
            'modelConfig' => [
                'allowed_resolutions' => ['480p', '720p', '1080p'],
                'default_resolution' => '720p',
                'resolution_prices' => ['480p' => 0.08, '720p' => 0.14, '1080p' => 0.25],
            ],
        ]);

        $this->assertSame('1080p', $captured['resolution']);
    }

    /**
     * Without per-resolution prices every tier bills at the row's flat rate, so
     * the user's choice is honoured instead of being clamped away.
     */
    public function testVideoResolutionKeepsTheRequestedTierOnAFlatRateRow(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured = json_decode($opts['body'], true);

            return $this->jsonResponse(['request_id' => 'req-123']);
        });

        $this->makeProvider(httpClient: $client)->startVideoOperation('a red cube', [
            'resolution' => '1080p',
            'modelConfig' => ['default_resolution' => '720p'],
        ]);

        $this->assertSame('1080p', $captured['resolution']);
    }

    /**
     * The render is already submitted and billable at this point, so an
     * unencodable handle must name itself instead of yielding an empty string
     * that only fails later as "invalid operation handle".
     */
    public function testEncodeOperationFailsLoudlyOnUnencodableInput(): void
    {
        $this->expectExceptionMessageContains('Cannot build xAI video operation handle for request req-123');
        $this->invokePrivate('encodeOperation', ['req-123', ['model' => "grok\xB1\x31"]]);
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

    /**
     * The blocking path must hand the progress callback the SAME array payload
     * GoogleProvider and HiggsfieldProvider emit. MediaGenerationHandler types
     * its callback `array`, so a bare percentage would be a TypeError mid-render.
     */
    public function testGenerateVideoReportsProgressAsTheSharedArrayPayload(): void
    {
        $responses = [
            $this->jsonResponse(['request_id' => 'req-123']),
            $this->jsonResponse(['status' => 'pending']),
            $this->jsonResponse([
                'status' => 'done',
                'video' => ['url' => 'https://bucket.x.ai/req-123.mp4', 'respect_moderation' => true],
            ]),
        ];
        $client = new MockHttpClient($responses);

        $updates = [];
        $videos = $this->makeProvider(httpClient: $client)->generateVideo('a red cube spinning', [
            'model' => 'grok-imagine-video',
            'duration' => 4,
            'resolution' => '480p',
            'progress_callback' => function (array $progress) use (&$updates): void {
                $updates[] = $progress;
            },
        ]);

        $this->assertSame('https://bucket.x.ai/req-123.mp4', $videos[0]['url']);
        $this->assertSame(4, $videos[0]['duration']);
        $this->assertCount(2, $updates);

        $this->assertSame('pending', $updates[0]['status']);
        $this->assertSame(1, $updates[0]['attempt']);
        $this->assertSame('req-123', $updates[0]['request_id']);
        $this->assertIsInt($updates[0]['elapsed_seconds']);
        // No `progress` field in xAI's poll response, so the elapsed-time
        // estimate keeps the bar moving instead of freezing at 0%.
        $this->assertGreaterThan(0, $updates[0]['percent']);

        $this->assertSame('done', $updates[1]['status']);
        $this->assertSame(100, $updates[1]['percent']);
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

    // ==================== TEXT TO SPEECH ====================

    public function testSynthesizeThrowsWithoutApiKey(): void
    {
        $this->expectExceptionMessageContains('XAI_API_KEY');
        $this->makeProvider(apiKey: null)->synthesize('hello');
    }

    public function testSynthesizeWritesTheRawAudioResponseToAFile(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'body' => json_decode($opts['body'], true)];

            return new MockResponse('ID3RAWMP3BYTES', ['response_headers' => ['content-type' => 'audio/mpeg']]);
        });

        $filename = $this->makeProvider(httpClient: $client)->synthesize('  Synaplan speaking  ', [
            'voice' => 'ara',
            'language' => 'de',
            'speed' => 1.25,
        ]);

        $this->assertSame('POST', $captured['method']);
        $this->assertSame('https://api.x.ai/v1/tts', $captured['url']);
        // The text is trimmed and `language` is always sent — /v1/tts requires it.
        // A non-default code proves the caller's hint wins over the `auto` default.
        $this->assertSame('Synaplan speaking', $captured['body']['text']);
        $this->assertSame('ara', $captured['body']['voice_id']);
        $this->assertSame('de', $captured['body']['language']);
        $this->assertSame(['codec' => 'mp3'], $captured['body']['output_format']);
        $this->assertSame(1.25, $captured['body']['speed']);

        $this->assertStringEndsWith('.mp3', $filename);
        $this->assertSame('ID3RAWMP3BYTES', file_get_contents($this->tempDir.'/'.$filename));
    }

    public function testSynthesizeDefaultsToEveAndAutoLanguage(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured = json_decode($opts['body'], true);

            return new MockResponse('AUDIO');
        });

        $this->makeProvider(httpClient: $client)->synthesize('hello');

        $this->assertSame('eve', $captured['voice_id']);
        $this->assertSame('auto', $captured['language']);
        $this->assertArrayNotHasKey('speed', $captured);
    }

    /**
     * `/v1/tts` returns raw bytes unless timestamps are requested. Decoding the
     * JSON envelope anyway keeps a future default flip from writing base64 text
     * into an audio file.
     */
    public function testSynthesizeDecodesABase64JsonEnvelope(): void
    {
        $client = new MockHttpClient(fn () => $this->jsonResponse([
            'audio' => base64_encode('DECODEDAUDIO'),
            'content_type' => 'audio/mpeg',
            'duration' => 1.5,
        ]));

        $filename = $this->makeProvider(httpClient: $client)->synthesize('hello');

        $this->assertSame('DECODEDAUDIO', file_get_contents($this->tempDir.'/'.$filename));
    }

    public function testSynthesizeRejectsTextBeyondTheProviderLimit(): void
    {
        $this->expectExceptionMessageContains('at most 15000 characters');
        $this->makeProvider()->synthesize(str_repeat('a', 15001));
    }

    public function testSynthesizeRejectsEmptyText(): void
    {
        $this->expectExceptionMessageContains('non-empty text');
        $this->makeProvider()->synthesize("  \n ");
    }

    public function testCodecDrivesFileExtensionAndStreamContentType(): void
    {
        $client = new MockHttpClient(fn () => new MockResponse('WAVBYTES'));
        $provider = $this->makeProvider(httpClient: $client);

        $filename = $provider->synthesize('hello', ['format' => 'wav']);

        $this->assertStringEndsWith('.wav', $filename);
        $this->assertSame('audio/wav', $provider->getStreamContentType(['format' => 'wav']));
        $this->assertSame('audio/mpeg', $provider->getStreamContentType());
        // An unsupported codec must fall back rather than reach xAI.
        $this->assertSame('audio/mpeg', $provider->getStreamContentType(['format' => 'flac']));
    }

    /**
     * The headerless codecs must not claim to be WAV — a raw stream in a .wav
     * file served as audio/wav is undecodable for every player.
     */
    public function testHeaderlessCodecsKeepTheirOwnExtensionAndMimeType(): void
    {
        $client = new MockHttpClient(fn () => new MockResponse('RAWPCM'));
        $provider = $this->makeProvider(httpClient: $client);

        $this->assertStringEndsWith('.pcm', $provider->synthesize('hello', ['format' => 'pcm']));
        $this->assertSame('audio/pcm', $provider->getStreamContentType(['format' => 'pcm']));
        $this->assertSame('audio/basic', $provider->getStreamContentType(['format' => 'mulaw']));
        $this->assertSame('audio/alaw', $provider->getStreamContentType(['format' => 'alaw']));
    }

    /**
     * TtsController clamps to OpenAI's 0.25-4.0 range; xAI rejects anything
     * outside 0.7-1.5, so the provider clamps instead of burning the request.
     */
    public function testSynthesizeClampsSpeedToTheRangeXaiAccepts(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured[] = json_decode($opts['body'], true)['speed'];

            return new MockResponse('AUDIO');
        });
        $provider = $this->makeProvider(httpClient: $client);

        $provider->synthesize('hello', ['speed' => 4.0]);
        $provider->synthesize('hello', ['speed' => 0.25]);
        $provider->synthesize('hello', ['speed' => 1.25]);

        $this->assertSame([1.5, 0.7, 1.25], $captured);
    }

    /**
     * xAI streams synthesis over a WebSocket only, so the provider reports no
     * streaming support and replays the unary result from disk instead.
     */
    public function testSynthesizeStreamReplaysTheUnaryResultAndCleansUp(): void
    {
        $client = new MockHttpClient(fn () => new MockResponse('STREAMED'));
        $provider = $this->makeProvider(httpClient: $client);

        $this->assertFalse($provider->supportsStreaming());

        $chunks = iterator_to_array($provider->synthesizeStream('hello'));

        $this->assertSame('STREAMED', implode('', $chunks));
        $this->assertSame([], glob($this->tempDir.'/tts_*') ?: []);
    }

    public function testGetVoicesMapsTheLiveRoster(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url];

            return $this->jsonResponse(['voices' => [
                ['voice_id' => 'eve', 'name' => 'Eve', 'description' => 'energetic'],
                ['voice_id' => 'custom01'],
                ['not' => 'a voice'],
            ]]);
        });

        $voices = $this->makeProvider(httpClient: $client)->getVoices();

        $this->assertSame('GET', $captured['method']);
        $this->assertSame('https://api.x.ai/v1/tts/voices', $captured['url']);
        $this->assertSame(['eve', 'custom01'], array_column($voices, 'id'));
        $this->assertSame('custom01', $voices[1]['name']);
    }

    public function testGetVoicesFallsBackToTheBuiltInRoster(): void
    {
        $client = new MockHttpClient(fn () => $this->jsonResponse(['error' => ['message' => 'boom']], 500));

        $voices = $this->makeProvider(httpClient: $client)->getVoices();

        $this->assertSame(['eve', 'ara', 'rex', 'sal', 'leo'], array_column($voices, 'id'));
        // Without a key we must not even attempt the request.
        $this->assertSame($voices, $this->makeProvider(apiKey: null)->getVoices());
    }

    // ==================== SPEECH TO TEXT ====================

    public function testTranscribeThrowsWithoutApiKey(): void
    {
        $this->expectExceptionMessageContains('XAI_API_KEY');
        $this->makeProvider(apiKey: null)->transcribe('clip.mp3');
    }

    public function testTranscribeThrowsWhenFileMissing(): void
    {
        $this->expectExceptionMessageContains('Audio file not found');
        $this->makeProvider()->transcribe('does-not-exist.mp3');
    }

    public function testTranscribeReturnsTextAndTheBillableDuration(): void
    {
        $file = $this->tempDir.'/clip.mp3';
        file_put_contents($file, 'AUDIO');

        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url];

            return $this->jsonResponse([
                'text' => 'The balance is $167,983.15.',
                // xAI currently always answers with an empty language.
                'language' => '',
                'duration' => 8.4,
                'words' => [['text' => 'The', 'start' => 0, 'end' => 0.24]],
            ]);
        });

        $result = $this->makeProvider(httpClient: $client)->transcribe('clip.mp3', ['language' => 'en']);

        $this->assertSame('POST', $captured['method']);
        $this->assertSame('https://api.x.ai/v1/stt', $captured['url']);
        $this->assertSame('The balance is $167,983.15.', $result['text']);
        // Falls back to the requested language while detection is disabled.
        $this->assertSame('en', $result['language']);
        // Drives per-second billing, so it must survive as a float.
        $this->assertSame(8.4, $result['duration']);
        $this->assertCount(1, $result['words']);
    }

    /**
     * xAI reads key terms from REPEATED `keyterm` fields. A plain field map would
     * serialise them as `keyterm[0]`/`keyterm[1]`, which the API ignores — the
     * caller would be billed for a request that silently lost its biasing.
     */
    public function testTranscribeSendsKeyTermsAsRepeatedFields(): void
    {
        file_put_contents($this->tempDir.'/clip.mp3', 'AUDIO');

        $body = '';
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$body): MockResponse {
            $body = $this->readMultipartBody($opts);

            return $this->jsonResponse(['text' => 'Synaplan runs on Grok.', 'duration' => 3.0]);
        });

        $this->makeProvider(httpClient: $client)->transcribe('clip.mp3', [
            'language' => 'en',
            'diarize' => true,
            'keyterm' => ['Synaplan', 'Grok Imagine'],
        ]);

        $this->assertSame(2, substr_count($body, 'name="keyterm"'));
        $this->assertStringNotContainsString('name="keyterm[', $body);
        $this->assertStringContainsString('Synaplan', $body);
        $this->assertStringContainsString('Grok Imagine', $body);
        // xAI requires the file part last, so the terms must precede it.
        $this->assertLessThan(strpos($body, 'name="file"'), strrpos($body, 'name="keyterm"'));
    }

    /**
     * Terms are hints, not content: an unusable one is dropped so the paid
     * transcription still happens, instead of failing the whole request.
     */
    public function testTranscribeDropsUnusableKeyTermsInsteadOfFailing(): void
    {
        file_put_contents($this->tempDir.'/clip.mp3', 'AUDIO');

        $body = '';
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$body): MockResponse {
            $body = $this->readMultipartBody($opts);

            return $this->jsonResponse(['text' => 'ok', 'duration' => 1.0]);
        });

        $result = $this->makeProvider(httpClient: $client)->transcribe('clip.mp3', [
            'keyterm' => [
                '  Synaplan  ',
                'Synaplan',
                '',
                '   ',
                str_repeat('a', 51),
            ],
        ]);

        $this->assertSame('ok', $result['text']);
        $this->assertSame(1, substr_count($body, 'name="keyterm"'));
        $this->assertStringContainsString('Synaplan', $body);
        $this->assertStringNotContainsString(str_repeat('a', 51), $body);
    }

    public function testTranscribeCapsKeyTermsAtTheDocumentedMaximum(): void
    {
        file_put_contents($this->tempDir.'/clip.mp3', 'AUDIO');

        $body = '';
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$body): MockResponse {
            $body = $this->readMultipartBody($opts);

            return $this->jsonResponse(['text' => 'ok', 'duration' => 1.0]);
        });

        $this->makeProvider(httpClient: $client)->transcribe('clip.mp3', [
            'keyterm' => array_map(static fn (int $i): string => 'term'.$i, range(1, 120)),
        ]);

        $this->assertSame(100, substr_count($body, 'name="keyterm"'));
    }

    public function testTranscribeSurfacesProviderErrors(): void
    {
        file_put_contents($this->tempDir.'/clip.mp3', 'AUDIO');

        $client = new MockHttpClient(fn () => $this->jsonResponse(['error' => ['message' => 'unsupported codec']], 400));

        $this->expectExceptionMessageContains('unsupported codec');
        $this->makeProvider(httpClient: $client)->transcribe('clip.mp3');
    }

    public function testTranslateAudioIsRejectedWithAnActionableMessage(): void
    {
        $this->expectExceptionMessageContains('no audio translation endpoint');
        $this->makeProvider()->translateAudio('clip.mp3', 'de');
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
     * Multipart bodies reach the client as a chunk-producing closure, so they
     * have to be drained before the field names can be inspected.
     *
     * @param array<string, mixed> $requestOptions
     */
    private function readMultipartBody(array $requestOptions): string
    {
        $body = $requestOptions['body'] ?? '';
        if (!$body instanceof \Closure) {
            return is_string($body) ? $body : '';
        }

        $raw = '';
        while ('' !== ($chunk = $body(8192))) {
            $raw .= $chunk;
        }

        return $raw;
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
