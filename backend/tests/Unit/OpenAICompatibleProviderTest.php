<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\AI\Credential\OpenAiCompatibleEndpointRegistry;
use App\AI\Exception\ProviderException;
use App\AI\Provider\OpenAICompatibleProvider;
use App\AI\StructuredOutput\StructuredOutputSchema;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OpenAICompatibleProviderTest extends TestCase
{
    private OpenAiCompatibleEndpointRegistry&Stub $registry;
    private OpenAICompatibleProvider $provider;

    protected function setUp(): void
    {
        $this->registry = $this->createStub(OpenAiCompatibleEndpointRegistry::class);
        $this->provider = new OpenAICompatibleProvider($this->registry, new NullLogger(), '/tmp');
    }

    public function testName(): void
    {
        $this->assertSame('openaicompatible', $this->provider->getName());
        $this->assertSame('OpenAI Compatible', $this->provider->getDisplayName());
    }

    public function testCapabilities(): void
    {
        $this->assertSame(['chat', 'embedding', 'vision'], $this->provider->getCapabilities());
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

    public function testBuildChatRequestWithoutStructuredOutputOmitsResponseFormat(): void
    {
        $request = $this->buildChatRequest([], [], 'some-model', false);

        $this->assertArrayNotHasKey('response_format', $request);
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
}
