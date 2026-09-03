<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\ToolCalling;

use App\AI\ToolCalling\ToolCallingCapability;
use PHPUnit\Framework\TestCase;

final class ToolCallingCapabilityTest extends TestCase
{
    private ToolCallingCapability $capability;

    protected function setUp(): void
    {
        $this->capability = new ToolCallingCapability();
    }

    public function testGroqIsWiredForTheRoutingHandOffIncludingStreaming(): void
    {
        self::assertTrue($this->capability->supports('groq', 'openai/gpt-oss-120b', false));
        self::assertTrue($this->capability->supports('groq', 'openai/gpt-oss-120b', true));
    }

    public function testAnthropicIsWiredForTheRoutingHandOffIncludingStreaming(): void
    {
        self::assertTrue($this->capability->supports('anthropic', 'claude-sonnet-5', false));
        self::assertTrue($this->capability->supports('anthropic', 'claude-sonnet-5', true));
    }

    public function testProviderNameIsMatchedCaseInsensitively(): void
    {
        self::assertTrue($this->capability->supports('Anthropic', 'claude-sonnet-5', false));
        self::assertTrue($this->capability->supports('GROQ', 'openai/gpt-oss-120b', false));
    }

    /**
     * The narrow allow-list is the safety property of this class. Every chat
     * provider can carry tools on the transport, but only the listed ones
     * hand the calls back in `tool_calls`; an unlisted provider must read as
     * "cannot", so the routing path keeps the AI-sorter round-trip instead of
     * declaring tools whose answer nobody collects.
     */
    public function testProvidersThatDoNotReturnToolCallsAreUnsupported(): void
    {
        foreach (['openai', 'google', 'ollama', 'mistral', 'xai', 'openaicompatible', 'triton'] as $provider) {
            self::assertFalse($this->capability->supports($provider, 'any-model', false), $provider);
            self::assertFalse($this->capability->supports($provider, 'any-model', true), $provider);
        }
    }

    /**
     * Both routing-wired providers conflict, for different reasons: Groq 400s
     * on the combination, and Anthropic's structured output IS a forced tool
     * call, so a caller's declarations would overwrite the schema's
     * `tools`/`tool_choice`.
     */
    public function testNeitherRoutingProviderCanCombineToolsWithASchema(): void
    {
        self::assertTrue($this->capability->conflictsWithStructuredOutput('groq'));
        self::assertTrue($this->capability->conflictsWithStructuredOutput('anthropic'));
        self::assertTrue($this->capability->conflictsWithStructuredOutput('Anthropic'));
    }

    public function testProvidersWithANativeSchemaModeReportNoConflict(): void
    {
        foreach (['openai', 'google', 'ollama', 'mistral', 'xai', 'triton'] as $provider) {
            self::assertFalse($this->capability->conflictsWithStructuredOutput($provider), $provider);
        }
    }
}
