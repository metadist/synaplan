<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\ToolCalling;

use App\AI\ToolCalling\ToolCallingCapability;
use App\AI\ToolCalling\ToolCallingDialect;
use PHPUnit\Framework\TestCase;

final class ToolCallingCapabilityTest extends TestCase
{
    private ToolCallingCapability $capability;

    protected function setUp(): void
    {
        $this->capability = new ToolCallingCapability();
    }

    public function testGroqSpeaksTheOpenAiFunctionsDialect(): void
    {
        self::assertSame(ToolCallingDialect::OPENAI_FUNCTIONS, $this->capability->dialect('groq'));
        self::assertTrue($this->capability->supports('groq', 'openai/gpt-oss-120b', false));
        self::assertTrue($this->capability->supports('groq', 'openai/gpt-oss-120b', true));
    }

    public function testAnthropicSpeaksItsOwnToolsDialect(): void
    {
        self::assertSame(ToolCallingDialect::ANTHROPIC_TOOLS, $this->capability->dialect('anthropic'));
        self::assertTrue($this->capability->supports('anthropic', 'claude-sonnet-5', false));
        self::assertTrue($this->capability->supports('anthropic', 'claude-sonnet-5', true));
    }

    public function testProviderNameIsMatchedCaseInsensitively(): void
    {
        self::assertTrue($this->capability->supports('Anthropic', 'claude-sonnet-5', false));
        self::assertSame(ToolCallingDialect::OPENAI_FUNCTIONS, $this->capability->dialect('GROQ'));
    }

    /**
     * The narrow allow-list is the safety property of this class: an
     * unlisted provider must read as "cannot", so the routing path keeps the
     * AI-sorter round-trip instead of silently dropping the tools.
     */
    public function testUnwiredProvidersAreUnsupportedEvenWhenTheirApiCouldDoIt(): void
    {
        foreach (['openai', 'google', 'ollama', 'mistral', 'xai', 'openaicompatible', 'triton'] as $provider) {
            self::assertFalse($this->capability->supports($provider, 'any-model', false), $provider);
            self::assertNull($this->capability->dialect($provider), $provider);
        }
    }

    /**
     * Both tool-capable providers conflict, for different reasons: Groq 400s
     * on the combination, and Anthropic's structured output IS a forced tool
     * call, so the two translators would fight over `tools`/`tool_choice`.
     */
    public function testNeitherToolCapableProviderCanCombineToolsWithASchema(): void
    {
        self::assertTrue($this->capability->conflictsWithStructuredOutput('groq'));
        self::assertTrue($this->capability->conflictsWithStructuredOutput('anthropic'));
        self::assertTrue($this->capability->conflictsWithStructuredOutput('Anthropic'));
    }

    public function testProvidersWithoutNativeToolCallingReportNoConflict(): void
    {
        foreach (['openai', 'google', 'ollama', 'mistral', 'xai', 'triton'] as $provider) {
            self::assertFalse($this->capability->conflictsWithStructuredOutput($provider), $provider);
        }
    }
}
