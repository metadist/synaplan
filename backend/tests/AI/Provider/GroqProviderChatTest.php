<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Provider\GroqProvider;
use App\AI\StructuredOutput\StructuredOutputSchema;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for GroqProvider chat request shaping.
 *
 * Chat runs through the openai-php client built internally, so the request
 * shape is asserted on the private {@see GroqProvider::buildChatOptions()}
 * helper, matching the Mistral/xAI/TrustedTokens unit-test pattern.
 */
class GroqProviderChatTest extends TestCase
{
    public function testChatThrowsWithoutModel(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Model must be specified');
        $this->makeProvider()->chat([['role' => 'user', 'content' => 'hi']]);
    }

    public function testChatThrowsWithoutApiKey(): void
    {
        $this->expectException(ProviderException::class);
        $this->makeProvider(apiKey: null)->chat([['role' => 'user', 'content' => 'hi']], ['model' => 'openai/gpt-oss-120b']);
    }

    // ==================== STRUCTURED OUTPUT (Phase 2a) ====================

    public function testChatOptionsMergeStructuredOutputAsJsonSchema(): void
    {
        $request = $this->buildChatOptions([], [
            'model' => 'openai/gpt-oss-120b',
            'structured_output' => new StructuredOutputSchema('sort_result', ['type' => 'object']),
        ], false);

        $this->assertSame('json_schema', $request['response_format']['type']);
        $this->assertSame('sort_result', $request['response_format']['json_schema']['name']);
        $this->assertSame(['type' => 'object'], $request['response_format']['json_schema']['schema']);
        // openai/gpt-oss-120b is the one documented strict-capable Groq model.
        $this->assertTrue($request['response_format']['json_schema']['strict']);
    }

    public function testChatOptionsWithoutStructuredOutputOmitResponseFormat(): void
    {
        $request = $this->buildChatOptions([], ['model' => 'openai/gpt-oss-120b'], false);

        $this->assertArrayNotHasKey('response_format', $request);
    }

    /**
     * Groq 400s when a schema request is combined with streaming — the guard
     * lives in {@see \App\AI\StructuredOutput\StructuredOutputCapability} and
     * must make the translator a no-op here rather than sending the schema.
     */
    public function testChatOptionsOmitStructuredOutputWhenStreaming(): void
    {
        $request = $this->buildChatOptions([], [
            'model' => 'openai/gpt-oss-120b',
            'structured_output' => new StructuredOutputSchema('sort_result', ['type' => 'object']),
        ], true);

        $this->assertArrayNotHasKey('response_format', $request);
        $this->assertTrue($request['stream']);
    }

    /**
     * A model outside the documented strict allow-list must not request
     * `strict: true` — the caller only declared `strict` as a preference.
     */
    public function testChatOptionsDowngradeStrictForUndocumentedModels(): void
    {
        $request = $this->buildChatOptions([], [
            'model' => 'llama-3.3-70b-versatile',
            'structured_output' => new StructuredOutputSchema('sort_result', ['type' => 'object']),
        ], false);

        $this->assertFalse($request['response_format']['json_schema']['strict']);
    }

    private function makeProvider(?string $apiKey = 'test-key'): GroqProvider
    {
        return new GroqProvider(new NullLogger(), $apiKey);
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
