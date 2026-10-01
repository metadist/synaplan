<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\AI\Credential\OpenAiCompatibleEndpointRegistry;
use App\AI\Exception\ProviderException;
use App\AI\Provider\OpenAICompatibleProvider;
use App\AI\StructuredOutput\StructuredOutputSchema;
use OpenAI\Contracts\ClientContract;
use OpenAI\Contracts\Resources\ChatContract;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenAICompatibleProviderTest extends TestCase
{
    private OpenAiCompatibleEndpointRegistry&Stub $registry;
    private OpenAICompatibleProvider $provider;

    protected function setUp(): void
    {
        $this->registry = $this->createStub(OpenAiCompatibleEndpointRegistry::class);
        $this->provider = new OpenAICompatibleProvider($this->registry, new NullLogger(), new MockHttpClient(), '/tmp');
    }

    public function testName(): void
    {
        $this->assertSame('openaicompatible', $this->provider->getName());
        $this->assertSame('OpenAI Compatible', $this->provider->getDisplayName());
    }

    public function testCapabilities(): void
    {
        $this->assertSame(['chat', 'embedding', 'vision', 'image_generation'], $this->provider->getCapabilities());
    }

    public function testAvailabilityDelegatesToRegistry(): void
    {
        $this->registry->method('hasAnyEndpoint')->willReturn(true);
        $this->assertTrue($this->provider->isAvailable());
    }

    public function testUnavailableWhenNoEndpoint(): void
    {
        $this->registry->method('hasAnyEndpoint')->willReturn(false);
        $this->assertFalse($this->provider->isAvailable());

        $status = $this->provider->getStatus();
        $this->assertFalse($status['healthy']);
    }

    public function testChatRequiresModel(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Model must be specified');
        $this->provider->chat([['role' => 'user', 'content' => 'hi']], []);
    }

    public function testChatFailsWhenNoEndpointResolved(): void
    {
        $this->registry->method('resolveForModel')->willReturn(null);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('No OpenAI-compatible endpoint resolved');
        $this->provider->chat([['role' => 'user', 'content' => 'hi']], ['model' => 'foo']);
    }

    public function testEmbedFailsWhenNoEndpointResolved(): void
    {
        $this->registry->method('resolveForModel')->willReturn(null);

        $this->expectException(ProviderException::class);
        $this->provider->embed('text', ['model' => 'foo']);
    }

    // ==================== STRUCTURED OUTPUT (Phase 2a) ====================

    public function testBuildChatRequestMergesStructuredOutputAsJsonSchema(): void
    {
        $request = $this->buildChatRequest([], [
            'structured_output' => new StructuredOutputSchema('sort_result', ['type' => 'object']),
        ], 'some-model', false);

        $this->assertSame('json_schema', $request['response_format']['type']);
        $this->assertSame('sort_result', $request['response_format']['json_schema']['name']);
        $this->assertSame(['type' => 'object'], $request['response_format']['json_schema']['schema']);
    }

    public function testBuildChatRequestDisablesThinkingWhenAsked(): void
    {
        $request = $this->buildChatRequest([], ['disable_thinking' => true], 'qwen3.8:27b', false);

        $this->assertFalse($request['think']);
    }

    public function testChatDropsThinkWhenTheEndpointRejectsTheField(): void
    {
        $seen = [];
        $chat = $this->createMock(ChatContract::class);
        $chat->method('create')->willReturnCallback(function (array $parameters) use (&$seen): CreateResponse {
            $seen[] = $parameters;
            if (array_key_exists('think', $parameters)) {
                throw new \RuntimeException("Additional properties are not allowed ('think' was unexpected)");
            }

            return CreateResponse::fake();
        });

        $result = $this->providerWithChat($chat)->chat(
            [['role' => 'user', 'content' => 'hi']],
            ['model' => 'qwen3.8:27b', 'disable_thinking' => true],
        );

        $this->assertCount(2, $seen);
        $this->assertFalse($seen[0]['think']);
        $this->assertArrayNotHasKey('think', $seen[1]);
        $this->assertIsString($result['content']);
        $this->assertNotSame('', $result['content']);
    }

    public function testChatDoesNotRetryAnUnrelatedFailure(): void
    {
        $chat = $this->createMock(ChatContract::class);
        $chat->expects($this->once())->method('create')->willThrowException(new \RuntimeException('invalid api key'));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('invalid api key');
        $this->providerWithChat($chat)->chat(
            [['role' => 'user', 'content' => 'hi']],
            ['model' => 'qwen3.8:27b', 'disable_thinking' => true],
        );
    }

    public function testBuildChatRequestLeavesThinkingAloneByDefault(): void
    {
        $request = $this->buildChatRequest([], [], 'qwen3.8:27b', false);

        $this->assertArrayNotHasKey('think', $request);
    }

    public function testBuildChatRequestWithoutStructuredOutputOmitsResponseFormat(): void
    {
        $request = $this->buildChatRequest([], [], 'some-model', false);

        $this->assertArrayNotHasKey('response_format', $request);
    }

    public function testGenerateImageRequiresModel(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Model must be specified');
        $this->provider->generateImage('a cup of coffee', []);
    }

    public function testGenerateImageFailsWhenNoEndpointResolved(): void
    {
        $this->registry->method('resolveForModel')->willReturn(null);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('No OpenAI-compatible endpoint resolved');
        $this->provider->generateImage('a cup of coffee', ['model' => 'flux.1-schnell']);
    }

    public function testGenerateImageRefusesAttachedReference(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('does not edit an attached picture');
        $this->provider->generateImage('make it blue', [
            'model' => 'flux.1-schnell',
            'images' => ['/tmp/ref.png'],
        ]);
    }

    public function testGenerateImagePostsImagesGenerationsAndReturnsDataUrl(): void
    {
        $seen = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse((string) json_encode([
                'data' => [[
                    'b64_json' => 'aGVsbG8=',
                    'revised_prompt' => 'a hand pouring coffee',
                ]],
            ]));
        });

        $images = $this->imageProvider($client)->generateImage('a hand pouring coffee', [
            'model' => 'flux.1-schnell',
            'size' => '1024x1024',
            'quality' => 'standard',
            'style' => 'vivid',
        ]);

        $this->assertIsArray($seen);
        $this->assertSame('POST', $seen['method']);
        $this->assertSame('http://localai.example:8080/v1/images/generations', $seen['url']);
        $this->assertIsArray($seen['options']);
        $body = json_decode((string) $seen['options']['body'], true);
        $this->assertIsArray($body);
        $this->assertSame('flux.1-schnell', $body['model']);
        $this->assertSame('a hand pouring coffee', $body['prompt']);
        $this->assertSame('1024x1024', $body['size']);
        $this->assertSame('b64_json', $body['response_format']);
        $this->assertArrayNotHasKey('quality', $body);
        $this->assertArrayNotHasKey('style', $body);
        $headers = implode("\n", $seen['options']['headers']);
        $this->assertStringContainsString('Authorization: Bearer sk-test', $headers);
        $this->assertStringContainsString('X-Lab: 1', $headers);
        $this->assertSame('data:image/png;base64,aGVsbG8=', $images[0]['url']);
        $this->assertSame('aGVsbG8=', $images[0]['b64_json']);
        $this->assertSame('a hand pouring coffee', $images[0]['revised_prompt']);
    }

    public function testGenerateImageResolvesRelativeUrlAgainstEndpointOrigin(): void
    {
        $client = new MockHttpClient(new MockResponse((string) json_encode([
            'data' => [['url' => '/generated/cup.png']],
        ])));

        $images = $this->imageProvider($client)->generateImage('a cup', ['model' => 'flux.1-schnell']);

        $this->assertSame('http://localai.example:8080/generated/cup.png', $images[0]['url']);
        $this->assertNull($images[0]['b64_json']);
    }

    public function testGenerateImageRetriesWithoutResponseFormatWhenRejected(): void
    {
        $bodies = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$bodies): MockResponse {
            $body = json_decode((string) $options['body'], true);
            $bodies[] = $body;
            if (isset($body['response_format'])) {
                return new MockResponse((string) json_encode([
                    'error' => ['message' => "Unknown parameter: 'response_format'"],
                ]), ['http_code' => 400]);
            }

            return new MockResponse((string) json_encode([
                'data' => [['url' => 'https://cdn.example/cup.png']],
            ]));
        });

        $images = $this->imageProvider($client)->generateImage('a cup', ['model' => 'flux.1-schnell']);

        $this->assertCount(2, $bodies);
        $this->assertArrayNotHasKey('response_format', $bodies[1]);
        $this->assertSame('https://cdn.example/cup.png', $images[0]['url']);
    }

    public function testGenerateImageThrowsProviderExceptionOnHttpError(): void
    {
        $client = new MockHttpClient(new MockResponse((string) json_encode([
            'error' => ['message' => 'model not loaded'],
        ]), ['http_code' => 404]));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('model not loaded');
        $this->imageProvider($client)->generateImage('a cup', ['model' => 'missing']);
    }

    public function testCreateVariationsIsUnsupported(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Image variations are not supported');
        $this->provider->createVariations('https://example/a.png');
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $options
     *
     * @return array<string, mixed>
     */
    private function buildChatRequest(array $messages, array $options, string $model, bool $stream): array
    {
        return (new \ReflectionClass($this->provider))->getMethod('buildChatRequest')->invoke($this->provider, $messages, $options, $model, $stream);
    }

    private function providerWithChat(ChatContract $chat): OpenAICompatibleProvider
    {
        $registry = $this->createStub(OpenAiCompatibleEndpointRegistry::class);
        $registry->method('resolveForModel')->willReturn([
            'name' => 'gateway',
            'label' => 'Gateway',
            'base_url' => 'http://gateway.example/v1',
            'api_key' => 'sk-test',
            'headers' => [],
            'capabilities' => ['chat'],
        ]);
        $client = $this->createMock(ClientContract::class);
        $client->method('chat')->willReturn($chat);
        $provider = new OpenAICompatibleProvider($registry, new NullLogger(), new MockHttpClient(), '/tmp');
        (new \ReflectionClass($provider))->getProperty('clients')->setValue($provider, ['gateway' => $client]);

        return $provider;
    }

    private function imageProvider(MockHttpClient $client): OpenAICompatibleProvider
    {
        $registry = $this->createStub(OpenAiCompatibleEndpointRegistry::class);
        $registry->method('resolveForModel')->willReturn([
            'name' => 'localai',
            'label' => 'Local AI',
            'base_url' => 'http://localai.example:8080/v1',
            'api_key' => 'sk-test',
            'headers' => ['X-Lab' => '1'],
            'capabilities' => ['text2pic'],
        ]);

        return new OpenAICompatibleProvider($registry, new NullLogger(), $client, '/tmp');
    }
}
