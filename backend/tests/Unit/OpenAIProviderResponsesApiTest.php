<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\AI\Provider\OpenAIProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAIProviderResponsesApiTest extends TestCase
{
    private LoggerInterface $logger;
    private HttpClientInterface $httpClient;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->httpClient = $this->createMock(HttpClientInterface::class);
    }

    public function testChatRequiresModel(): void
    {
        $provider = $this->createProvider();

        $this->expectException(\App\AI\Exception\ProviderException::class);
        $this->expectExceptionMessage('Model must be specified in options');

        $provider->chat([['role' => 'user', 'content' => 'Hello']], []);
    }

    public function testChatRequiresApiKey(): void
    {
        $provider = $this->createProvider(apiKey: null);

        $this->expectException(\App\AI\Exception\ProviderException::class);

        $provider->chat([['role' => 'user', 'content' => 'Hello']], ['model' => 'gpt-4o']);
    }

    public function testChatStreamRequiresModel(): void
    {
        $provider = $this->createProvider();

        $this->expectException(\App\AI\Exception\ProviderException::class);
        $this->expectExceptionMessage('Model must be specified in options');

        $provider->chatStream(
            [['role' => 'user', 'content' => 'Hello']],
            static fn () => null,
            []
        );
    }

    public function testChatStreamRequiresApiKey(): void
    {
        $provider = $this->createProvider(apiKey: null);

        $this->expectException(\App\AI\Exception\ProviderException::class);

        $provider->chatStream(
            [['role' => 'user', 'content' => 'Hello']],
            static fn () => null,
            ['model' => 'gpt-4o']
        );
    }

    public function testExtractSystemMessageViaReflection(): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'extractSystemMessage');

        $messages = [
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => 'Hello'],
        ];

        $result = $method->invoke($provider, $messages);
        $this->assertSame('You are helpful.', $result);
    }

    public function testExtractSystemMessageReturnsNullWhenMissing(): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'extractSystemMessage');

        $messages = [
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi!'],
        ];

        $result = $method->invoke($provider, $messages);
        $this->assertNull($result);
    }

    public function testRemoveSystemMessagesViaReflection(): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'removeSystemMessages');

        $messages = [
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi!'],
        ];

        $result = $method->invoke($provider, $messages);

        $this->assertCount(2, $result);
        $this->assertSame('user', $result[0]['role']);
        $this->assertSame('assistant', $result[1]['role']);
    }

    public function testRemoveSystemMessagesReindexesArray(): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'removeSystemMessages');

        $messages = [
            ['role' => 'system', 'content' => 'System prompt'],
            ['role' => 'user', 'content' => 'Q1'],
        ];

        $result = $method->invoke($provider, $messages);

        $this->assertSame([0], array_keys($result));
        $this->assertSame('user', $result[0]['role']);
    }

    public function testNormalizeResponsesUsageViaReflection(): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'normalizeResponsesUsage');

        $responseData = [
            'usage' => [
                'input_tokens' => 150,
                'output_tokens' => 80,
                'total_tokens' => 230,
                'input_tokens_details' => [
                    'cached_tokens' => 50,
                ],
            ],
        ];

        $result = $method->invoke($provider, $responseData);

        $this->assertSame(150, $result['prompt_tokens']);
        $this->assertSame(80, $result['completion_tokens']);
        $this->assertSame(230, $result['total_tokens']);
        $this->assertSame(50, $result['cached_tokens']);
        $this->assertSame(0, $result['cache_creation_tokens']);
    }

    public function testNormalizeResponsesUsageHandlesMissingData(): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'normalizeResponsesUsage');

        $result = $method->invoke($provider, []);

        $this->assertSame(0, $result['prompt_tokens']);
        $this->assertSame(0, $result['completion_tokens']);
        $this->assertSame(0, $result['total_tokens']);
        $this->assertSame(0, $result['cached_tokens']);
        $this->assertSame(0, $result['cache_creation_tokens']);
    }

    public function testBuildResponsesRequestViaReflection(): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'buildResponsesRequest');

        $messages = [
            ['role' => 'system', 'content' => 'Be helpful'],
            ['role' => 'user', 'content' => 'Hello'],
        ];

        $result = $method->invoke($provider, $messages, 'gpt-4o', false, []);

        $this->assertSame('gpt-4o', $result['model']);
        $this->assertSame('Be helpful', $result['instructions']);
        $this->assertTrue($result['store']);
        $this->assertSame(4096, $result['max_output_tokens']);
        $this->assertSame(0.7, $result['temperature']);
        $this->assertCount(1, $result['input']);
        $this->assertSame('user', $result['input'][0]['role']);
        $this->assertArrayNotHasKey('previous_response_id', $result);
    }

    public function testBuildResponsesRequestSystemOnlyMessagesFallback(): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'buildResponsesRequest');

        $messages = [
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
        ];

        $result = $method->invoke($provider, $messages, 'gpt-4o', false, []);

        $this->assertSame('You are a helpful assistant.', $result['instructions']);
        $this->assertNotEmpty($result['input']);
        $this->assertCount(1, $result['input']);
        $this->assertSame('user', $result['input'][0]['role']);
    }

    public function testBuildResponsesRequestWithPreviousResponseId(): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'buildResponsesRequest');

        $messages = [
            ['role' => 'user', 'content' => 'Follow up question'],
        ];

        $options = ['previous_response_id' => 'resp_abc123'];
        $result = $method->invoke($provider, $messages, 'gpt-4o', false, $options);

        $this->assertSame('resp_abc123', $result['previous_response_id']);
    }

    public function testBuildResponsesRequestReasoningModelSkipsTemperature(): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'buildResponsesRequest');

        $messages = [['role' => 'user', 'content' => 'Hello']];

        $result = $method->invoke($provider, $messages, 'o3-mini', true, []);

        $this->assertArrayNotHasKey('temperature', $result);
    }

    public function testBuildResponsesRequestStoreDisabled(): void
    {
        $provider = $this->createProvider(storeResponses: false);
        $method = new \ReflectionMethod($provider, 'buildResponsesRequest');

        $messages = [['role' => 'user', 'content' => 'Hello']];

        $result = $method->invoke($provider, $messages, 'gpt-4o', false, []);

        $this->assertFalse($result['store']);
    }

    public function testBuildResponsesRequestPreviousResponseIdWithStoreDisabled(): void
    {
        $provider = $this->createProvider(storeResponses: false);
        $method = new \ReflectionMethod($provider, 'buildResponsesRequest');

        $messages = [['role' => 'user', 'content' => 'Follow up']];
        $options = ['previous_response_id' => 'resp_abc123'];

        $result = $method->invoke($provider, $messages, 'gpt-4o', false, $options);

        $this->assertSame('resp_abc123', $result['previous_response_id']);
    }

    public function testBuildResponsesRequestReasoningWithSummary(): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'buildResponsesRequest');

        $messages = [['role' => 'user', 'content' => 'Explain this']];
        $options = ['reasoning' => true];

        $result = $method->invoke($provider, $messages, 'o3-mini', true, $options);

        $this->assertArrayHasKey('reasoning', $result);
        $this->assertSame('auto', $result['reasoning']['summary']);
        $this->assertSame('medium', $result['reasoning']['effort']);
    }

    public function testBuildResponsesRequestNoReasoningForNonReasoningModel(): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'buildResponsesRequest');

        $messages = [['role' => 'user', 'content' => 'Hello']];
        $options = ['reasoning' => true];

        $result = $method->invoke($provider, $messages, 'gpt-4o', false, $options);

        $this->assertArrayNotHasKey('reasoning', $result);
    }

    /**
     * Phase 1e parallel for OpenAI on gpt-5.5+: when the chat pipeline calls
     * with `reasoning => false` (Thinking toggle off) the request must skip
     * chain-of-thought entirely. gpt-5.5 renamed the original `'minimal'`
     * tier to `'none'` and rejects `'minimal'` with HTTP 400, so the lowest
     * tier on this family is `'none'`.
     */
    public function testBuildResponsesRequestDefaultChatUsesNoneEffortOnGpt55(): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'buildResponsesRequest');

        $messages = [['role' => 'user', 'content' => 'Hi']];
        $options = ['reasoning' => false];

        $result = $method->invoke($provider, $messages, 'gpt-5.5', true, $options);

        $this->assertArrayHasKey('reasoning', $result);
        $this->assertSame('none', $result['reasoning']['effort']);
        // No chain-of-thought = nothing to summarise.
        $this->assertArrayNotHasKey('summary', $result['reasoning']);
    }

    /**
     * The original gpt-5 family still uses the legacy `'minimal'` tier name
     * (gpt-5.5 renamed it to `'none'`). Verify we pick the right one per
     * model family.
     */
    public function testBuildResponsesRequestDefaultChatUsesMinimalEffortOnOriginalGpt5(): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'buildResponsesRequest');

        $messages = [['role' => 'user', 'content' => 'Hi']];
        $options = ['reasoning' => false];

        $result = $method->invoke($provider, $messages, 'gpt-5', true, $options);

        $this->assertArrayHasKey('reasoning', $result);
        $this->assertSame('minimal', $result['reasoning']['effort']);
        $this->assertArrayNotHasKey('summary', $result['reasoning']);
    }

    /**
     * o-series models reject both `'minimal'` and `'none'`, so the auto-disable
     * path has to fall back to `'low'` (their lowest available tier). Still
     * faster than the server-side default of `medium`.
     */
    public function testBuildResponsesRequestDefaultChatFallsBackToLowOnOSeries(): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'buildResponsesRequest');

        $messages = [['role' => 'user', 'content' => 'Hi']];
        $options = ['reasoning' => false];

        $result = $method->invoke($provider, $messages, 'o3-mini', true, $options);

        $this->assertArrayHasKey('reasoning', $result);
        $this->assertSame('low', $result['reasoning']['effort']);
        $this->assertArrayNotHasKey('summary', $result['reasoning']);
    }

    /**
     * gpt-5.5+ exposes an `'xhigh'` tier above `'high'`. Older families don't
     * accept it — make sure we pass it through on gpt-5.5 and clamp to
     * `'high'` everywhere else.
     */
    public function testBuildResponsesRequestXHighEffortPassesThroughOnGpt55(): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'buildResponsesRequest');

        $messages = [['role' => 'user', 'content' => 'Solve this carefully']];
        $options = ['reasoning_effort' => 'xhigh'];

        $result = $method->invoke($provider, $messages, 'gpt-5.5', true, $options);

        $this->assertSame('xhigh', $result['reasoning']['effort']);
        $this->assertSame('auto', $result['reasoning']['summary']);
    }

    public function testBuildResponsesRequestXHighEffortClampsToHighOnGpt5(): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'buildResponsesRequest');

        $messages = [['role' => 'user', 'content' => 'Solve this carefully']];
        $options = ['reasoning_effort' => 'xhigh'];

        $result = $method->invoke($provider, $messages, 'gpt-5', true, $options);

        $this->assertSame('high', $result['reasoning']['effort']);
        $this->assertSame('auto', $result['reasoning']['summary']);
    }

    /**
     * Per-family lowest-tier mapping. The catalog ships `gpt-5.4` (BIDs 180,
     * 181) and `gpt-5.5` / `gpt-5.5-pro` (BIDs 204-207); both must continue
     * to work with default chat (Thinking toggle off). Lock the mapping
     * down so a future refactor of `lowestEffortTier()` can't silently
     * regress existing users.
     *
     * @param string $model expected lowest-tier output for this model
     */
    #[DataProvider('lowestEffortTierProvider')]
    public function testLowestEffortTierPerModelFamily(string $model, string $expected): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'lowestEffortTier');

        $this->assertSame($expected, $method->invoke($provider, $model));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function lowestEffortTierProvider(): array
    {
        return [
            // gpt-5.5+ family — accepts 'none', rejects 'minimal'
            'gpt-5.5' => ['gpt-5.5',                  'none'],
            'gpt-5.5-pro' => ['gpt-5.5-pro',              'none'],
            'gpt-5.5 with date suffix' => ['gpt-5.5-2026-01-15',       'none'],
            'gpt-5.5-pro with date' => ['gpt-5.5-pro-2026-01-15',   'none'],
            // gpt-5.x where x < 5 — original 'minimal' tier
            'gpt-5' => ['gpt-5',                    'minimal'],
            'gpt-5.0' => ['gpt-5.0',                  'minimal'],
            'gpt-5.1' => ['gpt-5.1',                  'minimal'],
            'gpt-5.2' => ['gpt-5.2',                  'minimal'],
            'gpt-5.3' => ['gpt-5.3',                  'minimal'],
            'gpt-5.4 (in catalog)' => ['gpt-5.4',                  'minimal'],
            'gpt-5 with date suffix' => ['gpt-5-2025-08-06',         'minimal'],
            'gpt-5.4 with date' => ['gpt-5.4-2025-12-01',       'minimal'],
            // o-series — rejects both 'minimal' and 'none'
            'o1' => ['o1',                       'low'],
            'o1-mini' => ['o1-mini',                  'low'],
            'o3' => ['o3',                       'low'],
            'o3-mini' => ['o3-mini',                  'low'],
            'o4-mini' => ['o4-mini',                  'low'],
        ];
    }

    /**
     * Regression test for the 5.4-and-lower concern: a user whose default
     * chat model is gpt-5.4 must keep getting a working request. The
     * previous behaviour (no reasoning block, server default = `medium`)
     * is upgraded to `effort='minimal'`, which the gpt-5.x line accepts —
     * not `'none'`, which only gpt-5.5+ accepts.
     */
    public function testGpt54DefaultChatSendsMinimalNotNone(): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'buildResponsesRequest');

        $messages = [['role' => 'user', 'content' => 'Hi']];
        $options = ['reasoning' => false];

        $result = $method->invoke($provider, $messages, 'gpt-5.4', true, $options);

        $this->assertSame('minimal', $result['reasoning']['effort']);
        $this->assertNotSame('none', $result['reasoning']['effort']);
    }

    /**
     * @param string $errorMessage exception message to test against the matcher
     */
    #[DataProvider('reasoningRejectionErrorProvider')]
    public function testReasoningEffortRejectedErrorDetector(string $errorMessage, bool $expected): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'isReasoningEffortRejectedError');

        $this->assertSame($expected, $method->invoke($provider, new \Exception($errorMessage)));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function reasoningRejectionErrorProvider(): array
    {
        return [
            // Real OpenAI 400 messages we want to recover from
            'gpt-5.5 rejecting minimal' => [
                "Unsupported value: 'minimal' is not supported with the 'gpt-5.5' model. Supported values are: 'none', 'low', 'medium', 'high', and 'xhigh'.",
                true,
            ],
            'hypothetical future model rejecting none' => [
                "Unsupported value: 'none' is not supported with the 'gpt-5.6' model. Supported values are: 'minimal', 'low', 'medium', 'high'.",
                true,
            ],
            'o-series rejecting minimal' => [
                "Unsupported value: 'minimal' is not supported with the 'o1' model. Supported values are: 'low', 'medium', 'high'.",
                true,
            ],
            // Unrelated errors must NOT trigger the retry (otherwise we'd
            // strip reasoning on every error, masking real bugs)
            'rate limit' => [
                'Rate limit reached for gpt-5.5 in organization org-abc on tokens per min',
                false,
            ],
            'invalid api key' => [
                'Incorrect API key provided',
                false,
            ],
            'previous_response_id error' => [
                "previous_response_id 'resp_abc' is invalid",
                false,
            ],
        ];
    }

    /**
     * Pre-flight cache: once a model has rejected our reasoning tier, the
     * NEXT request for that model must skip the reasoning block before it
     * even hits the wire. Otherwise we'd pay a 400 + retry round-trip on
     * every subsequent message.
     */
    public function testApplyReasoningRejectionCacheStripsReasoningWhenModelKnownToReject(): void
    {
        $provider = $this->createProvider();

        $cacheProperty = new \ReflectionProperty($provider, 'reasoningRejectionCache');
        $cacheProperty->setValue($provider, ['gpt-future' => true]);

        $method = new \ReflectionMethod($provider, 'applyReasoningRejectionCache');

        $result = $method->invoke($provider, [
            'model' => 'gpt-future',
            'reasoning' => ['effort' => 'minimal'],
            'input' => [],
        ]);

        $this->assertArrayNotHasKey('reasoning', $result);
    }

    public function testApplyReasoningRejectionCachePassesThroughForKnownGoodModel(): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'applyReasoningRejectionCache');

        $result = $method->invoke($provider, [
            'model' => 'gpt-5.5',
            'reasoning' => ['effort' => 'none'],
            'input' => [],
        ]);

        $this->assertSame(['effort' => 'none'], $result['reasoning']);
    }

    /**
     * When the caller passes neither `reasoning` nor `reasoning_effort`, we
     * must NOT inject a reasoning block — preserve the prior behaviour so
     * tests / advanced callers that opt out of the cross-provider semantics
     * keep getting OpenAI's server-side default.
     */
    public function testBuildResponsesRequestNoReasoningSignalSendsNoBlock(): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'buildResponsesRequest');

        $messages = [['role' => 'user', 'content' => 'Hi']];

        $result = $method->invoke($provider, $messages, 'gpt-5.5', true, []);

        $this->assertArrayNotHasKey('reasoning', $result);
    }

    /**
     * Cross-provider `reasoning_effort` knob takes precedence over the
     * legacy boolean flag. Verifies the explicit-tier path lines up with
     * what GoogleProvider does for the same input.
     */
    public function testBuildResponsesRequestExplicitEffortHigh(): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'buildResponsesRequest');

        $messages = [['role' => 'user', 'content' => 'Solve this carefully']];
        $options = ['reasoning' => true, 'reasoning_effort' => 'high'];

        $result = $method->invoke($provider, $messages, 'gpt-5.5', true, $options);

        $this->assertSame('high', $result['reasoning']['effort']);
        $this->assertSame('auto', $result['reasoning']['summary']);
    }

    /**
     * Native passthrough path: when the caller already supplies a fully
     * formed `reasoning` array, send it verbatim (advanced override).
     */
    public function testBuildResponsesRequestArrayReasoningIsPassedThroughVerbatim(): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'buildResponsesRequest');

        $messages = [['role' => 'user', 'content' => 'Hi']];
        $options = ['reasoning' => ['effort' => 'high', 'summary' => 'concise']];

        $result = $method->invoke($provider, $messages, 'gpt-5.5', true, $options);

        $this->assertSame('high', $result['reasoning']['effort']);
        $this->assertSame('concise', $result['reasoning']['summary']);
    }

    public function testReduceToLastUserMessageViaReflection(): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'reduceToLastUserMessage');

        $input = [
            ['role' => 'user', 'content' => 'First question'],
            ['role' => 'assistant', 'content' => 'First answer'],
            ['role' => 'user', 'content' => 'Follow up'],
        ];

        $result = $method->invoke($provider, $input);

        $this->assertCount(1, $result);
        $this->assertSame('Follow up', $result[0]['content']);
    }

    public function testReduceToLastUserMessageFallsBackToFullInput(): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'reduceToLastUserMessage');

        $input = [
            ['role' => 'assistant', 'content' => 'Only assistant messages'],
        ];

        $result = $method->invoke($provider, $input);

        $this->assertCount(1, $result);
        $this->assertSame('Only assistant messages', $result[0]['content']);
    }

    public function testIsPreviousResponseErrorViaReflection(): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'isPreviousResponseError');

        $this->assertTrue($method->invoke($provider, new \Exception('previous_response_id is invalid')));
        $this->assertTrue($method->invoke($provider, new \Exception('invalid_response_id')));
        $this->assertTrue($method->invoke($provider, new \Exception("Previous response with id 'resp_abc123' not found.")));
        $this->assertTrue($method->invoke($provider, new \Exception('previous response expired')));
        $this->assertFalse($method->invoke($provider, new \Exception('rate limit exceeded')));
        $this->assertFalse($method->invoke($provider, new \Exception('model not found')));
    }

    public function testProviderName(): void
    {
        $provider = $this->createProvider();
        $this->assertSame('openai', $provider->getName());
    }

    public function testIsAvailableWithKey(): void
    {
        $provider = $this->createProvider();
        $this->assertTrue($provider->isAvailable());
    }

    public function testIsNotAvailableWithoutKey(): void
    {
        $provider = $this->createProvider(apiKey: null);
        $this->assertFalse($provider->isAvailable());
    }

    public function testSupportsResponsesApiIncludesAllGptImageFamily(): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'supportsResponsesApi');

        $this->assertTrue($method->invoke($provider, 'gpt-image-1'));
        $this->assertTrue($method->invoke($provider, 'gpt-image-1.5'));
        $this->assertTrue($method->invoke($provider, 'gpt-image-2'));
    }

    /**
     * Integration-level regression test for the `/pic` bug:
     * any `gpt-image-*` model (including the new `gpt-image-2`) must dispatch
     * to the Image Generations endpoint, NOT the legacy DALL-E client path.
     * Pic2pic with reference images must go through the Responses API.
     *
     * @param string[] $inputImages
     */
    #[DataProvider('imageApiRoutingProvider')]
    public function testImageApiRoutingForAllSupportedModels(string $model, array $inputImages, string $expectedApi): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'selectImageApi');

        $this->assertSame(
            $expectedApi,
            $method->invoke($provider, $model, $inputImages),
            sprintf('Model "%s" with %d input image(s) must route to "%s" API', $model, \count($inputImages), $expectedApi)
        );
    }

    /**
     * @return array<string, array{0: string, 1: string[], 2: string}>
     */
    public static function imageApiRoutingProvider(): array
    {
        return [
            // Text-to-image: gpt-image-* must use the Image Generations endpoint
            'gpt-image-1 text2pic' => ['gpt-image-1',   [],                  'gpt_image'],
            'gpt-image-1.5 text2pic' => ['gpt-image-1.5', [],                  'gpt_image'],
            'gpt-image-2 text2pic' => ['gpt-image-2',   [],                  'gpt_image'],
            // Pic2pic: gpt-image-* with reference images must use the Responses API
            'gpt-image-1 pic2pic' => ['gpt-image-1',   ['/tmp/ref1.png'],   'responses'],
            'gpt-image-1.5 pic2pic' => ['gpt-image-1.5', ['/tmp/ref1.png'],   'responses'],
            'gpt-image-2 pic2pic' => ['gpt-image-2',   ['/tmp/ref1.png'],   'responses'],
            // DALL-E stays on the legacy Images API client path
            'dall-e-3 text2pic' => ['dall-e-3',      [],                  'dalle'],
            'dall-e-2 text2pic' => ['dall-e-2',      [],                  'dalle'],
            // DALL-E does NOT support the Responses API, so reference images still fall back to DALL-E
            'dall-e-3 with images' => ['dall-e-3',      ['/tmp/ref1.png'],   'dalle'],
        ];
    }

    private function createProvider(?string $apiKey = 'test-key', bool $storeResponses = true): OpenAIProvider
    {
        return new OpenAIProvider(
            $this->logger,
            $this->httpClient,
            $apiKey,
            '/tmp/uploads',
            $storeResponses,
        );
    }
}
