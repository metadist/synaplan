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

    // ==================== NATIVE TOOL CALLING (Phase 9) ====================

    public function testChatOptionsForwardToolDeclarationsAndTheirChoice(): void
    {
        $request = $this->buildChatOptions([], [
            'model' => 'openai/gpt-oss-120b',
            'tools' => [['type' => 'function', 'function' => ['name' => 'handoff_mediamaker', 'description' => 'Generate media.', 'parameters' => ['type' => 'object']]]],
            'tool_choice' => 'auto',
        ], false);

        $this->assertSame('function', $request['tools'][0]['type']);
        $this->assertSame('handoff_mediamaker', $request['tools'][0]['function']['name']);
        $this->assertSame('auto', $request['tool_choice']);
    }

    /**
     * Unlike a JSON schema, tools DO survive streaming on Groq — the routing
     * hand-off has to work on the streaming web path, which is the hot one.
     */
    public function testChatOptionsKeepToolsWhenStreaming(): void
    {
        $request = $this->buildChatOptions([], [
            'model' => 'openai/gpt-oss-120b',
            'tools' => [['type' => 'function', 'function' => ['name' => 'handoff_mediamaker', 'description' => 'Generate media.', 'parameters' => ['type' => 'object']]]],
            'tool_choice' => 'auto',
        ], true);

        $this->assertCount(1, $request['tools']);
        $this->assertTrue($request['stream']);
    }

    public function testChatOptionsWithoutToolsOmitThemEntirely(): void
    {
        $request = $this->buildChatOptions([], ['model' => 'openai/gpt-oss-120b'], false);

        $this->assertArrayNotHasKey('tools', $request);
        $this->assertArrayNotHasKey('tool_choice', $request);
    }

    /**
     * Groq 400s on a request carrying both, so one of them has to go. The
     * schema is the caller's output contract — something downstream parses
     * against it — while "the model called no tool" is already a valid
     * outcome of every toolset we declare, so the tools are what gets
     * dropped.
     */
    public function testToolsAreDroppedWhenTheSameRequestCarriesAJsonSchema(): void
    {
        $request = $this->buildChatOptions([], [
            'model' => 'openai/gpt-oss-120b',
            'structured_output' => new StructuredOutputSchema('sort_result', ['type' => 'object']),
            'tools' => [['type' => 'function', 'function' => ['name' => 'handoff_mediamaker', 'description' => 'Generate media.', 'parameters' => ['type' => 'object']]]],
            'tool_choice' => 'auto',
        ], false);

        $this->assertArrayNotHasKey('tools', $request);
        $this->assertArrayNotHasKey('tool_choice', $request);
        $this->assertSame('sort_result', $request['response_format']['json_schema']['name']);
    }

    /**
     * Streaming already drops the schema on Groq
     * ({@see self::testChatOptionsOmitStructuredOutputWhenStreaming()}), so
     * there is nothing left to conflict with and the tools must survive —
     * the guard may not fire on the request that is actually valid.
     */
    public function testToolsSurviveAStreamingRequestWhoseSchemaWasAlreadyDropped(): void
    {
        $request = $this->buildChatOptions([], [
            'model' => 'openai/gpt-oss-120b',
            'structured_output' => new StructuredOutputSchema('sort_result', ['type' => 'object']),
            'tools' => [['type' => 'function', 'function' => ['name' => 'handoff_mediamaker', 'description' => 'Generate media.', 'parameters' => ['type' => 'object']]]],
            'tool_choice' => 'auto',
        ], true);

        $this->assertArrayNotHasKey('response_format', $request);
        $this->assertCount(1, $request['tools']);
    }

    /**
     * An empty toolset is not a declaration: sending `tools: []` makes Groq
     * reject the request, so the guard must not fire on a caller that merely
     * carries the key.
     */
    public function testAnEmptyToolListDoesNotUnsetTheSchema(): void
    {
        $request = $this->buildChatOptions([], [
            'model' => 'openai/gpt-oss-120b',
            'structured_output' => new StructuredOutputSchema('sort_result', ['type' => 'object']),
            'tools' => [],
        ], false);

        $this->assertSame('sort_result', $request['response_format']['json_schema']['name']);
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
