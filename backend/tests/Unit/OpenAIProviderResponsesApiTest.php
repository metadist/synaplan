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
     * Phase 1e parallel for OpenAI: when the chat pipeline calls with
     * `reasoning => false` (the user did NOT enable the Thinking UI toggle)
     * we want gpt-5* to skip its server-default `medium` reasoning so the
     * first token arrives in ~hundreds of ms instead of ~1-3 s.
     */
    public function testBuildResponsesRequestDefaultChatUsesMinimalEffortOnGpt5(): void
    {
        $provider = $this->createProvider();
        $method = new \ReflectionMethod($provider, 'buildResponsesRequest');

        $messages = [['role' => 'user', 'content' => 'Hi']];
        $options = ['reasoning' => false];

        $result = $method->invoke($provider, $messages, 'gpt-5.5', true, $options);

        $this->assertArrayHasKey('reasoning', $result);
        $this->assertSame('minimal', $result['reasoning']['effort']);
        // Minimal effort = no chain-of-thought worth summarising.
        $this->assertArrayNotHasKey('summary', $result['reasoning']);
    }

    /**
     * o-series models reject `effort = 'minimal'`, so the auto-disable path
     * has to fall back to `'low'` (their lowest available tier). Still
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
