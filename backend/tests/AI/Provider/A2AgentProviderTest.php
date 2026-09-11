<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Provider\A2AgentProvider;
use App\AI\StructuredOutput\StructuredOutputSchema;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for A2AgentProvider.
 *
 * Chat/vision run through the openai-php client built internally, so only
 * metadata and preconditions (missing model / missing API key) are asserted
 * here — matching the TrustedTokens unit-test pattern.
 */
class A2AgentProviderTest extends TestCase
{
    public function testMetadata(): void
    {
        $provider = $this->makeProvider();

        $this->assertSame('a2agent', $provider->getName());
        $this->assertSame('A2Agent', $provider->getDisplayName());
        $this->assertTrue($provider->isAvailable());
        $this->assertStringContainsString('mainland-China', $provider->getDescription());
    }

    public function testCapabilities(): void
    {
        $capabilities = $this->makeProvider()->getCapabilities();

        $this->assertContains('chat', $capabilities);
        $this->assertContains('vision', $capabilities);
        $this->assertNotContains('speech_to_text', $capabilities);
    }

    public function testDefaultModels(): void
    {
        $defaults = $this->makeProvider()->getDefaultModels();

        $this->assertSame('deepseek-v4-pro', $defaults['chat']);
        $this->assertSame('qwen3.8-flash', $defaults['vision']);
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

        $this->assertArrayHasKey('A2AGENT_API_KEY', $vars);
        $this->assertTrue($vars['A2AGENT_API_KEY']['required']);
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
            ['model' => 'deepseek-v4-pro'],
        );
    }

    public function testChatOptionsMergeStructuredOutputAsJsonSchema(): void
    {
        $request = $this->buildChatOptions([], [
            'model' => 'deepseek-v4-pro',
            'structured_output' => new StructuredOutputSchema('sort_result', ['type' => 'object']),
        ], false);

        $this->assertSame('json_schema', $request['response_format']['type']);
        $this->assertSame('sort_result', $request['response_format']['json_schema']['name']);
        $this->assertSame(['type' => 'object'], $request['response_format']['json_schema']['schema']);
    }

    public function testChatOptionsWithoutStructuredOutputOmitResponseFormat(): void
    {
        $request = $this->buildChatOptions([], ['model' => 'deepseek-v4-pro'], false);

        $this->assertArrayNotHasKey('response_format', $request);
    }

    private function makeProvider(?string $apiKey = 'test-key'): A2AgentProvider
    {
        return new A2AgentProvider(new NullLogger(), $apiKey);
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
