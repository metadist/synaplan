<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\StructuredOutput;

use App\AI\StructuredOutput\StructuredOutputCapability;
use App\AI\StructuredOutput\StructuredOutputDialect;
use PHPUnit\Framework\TestCase;

final class StructuredOutputCapabilityTest extends TestCase
{
    private StructuredOutputCapability $capability;

    protected function setUp(): void
    {
        $this->capability = new StructuredOutputCapability();
    }

    public function testTritonNeverSupportsStructuredOutput(): void
    {
        self::assertFalse($this->capability->supports('triton', 'any-model', false));
        self::assertFalse($this->capability->supports('triton', 'any-model', true));
        self::assertNull($this->capability->dialect('triton'));
    }

    public function testGroqSupportsNonStreamingButNotStreaming(): void
    {
        self::assertTrue($this->capability->supports('groq', 'openai/gpt-oss-120b', false));
        self::assertFalse($this->capability->supports('groq', 'openai/gpt-oss-120b', true));
    }

    public function testGroqStrictOnlyForDocumentedModels(): void
    {
        self::assertTrue($this->capability->supportsStrict('groq', 'openai/gpt-oss-120b'));
        self::assertFalse($this->capability->supportsStrict('groq', 'some-undocumented-model'));
        self::assertFalse($this->capability->supportsStrict('groq', null));
    }

    /**
     * Provider and model both come out of BMODELS, where casing is not
     * normalised — a capitalised id must not silently downgrade a documented
     * model to the non-strict schema.
     */
    public function testStrictLookupIgnoresCasingOnBothProviderAndModel(): void
    {
        self::assertTrue($this->capability->supportsStrict('Groq', 'OpenAI/GPT-OSS-120B'));
    }

    public function testStrictNeverAssumedForOtherProviders(): void
    {
        self::assertFalse($this->capability->supportsStrict('openai', 'gpt-5'));
        self::assertFalse($this->capability->supportsStrict('anthropic', 'claude-sonnet-5'));
    }

    /**
     * @return array<string, array{0: string, 1: StructuredOutputDialect}>
     */
    public static function dialectMapping(): array
    {
        return [
            'groq' => ['groq', StructuredOutputDialect::OPENAI_JSON_SCHEMA],
            'mistral' => ['mistral', StructuredOutputDialect::OPENAI_JSON_SCHEMA],
            'xai' => ['xai', StructuredOutputDialect::OPENAI_JSON_SCHEMA],
            'trustedtokens' => ['trustedtokens', StructuredOutputDialect::OPENAI_JSON_SCHEMA],
            'openaicompatible' => ['openaicompatible', StructuredOutputDialect::OPENAI_JSON_SCHEMA],
            'huggingface' => ['huggingface', StructuredOutputDialect::OPENAI_JSON_SCHEMA],
            'openai' => ['openai', StructuredOutputDialect::OPENAI_RESPONSES_TEXT_FORMAT],
            'google' => ['google', StructuredOutputDialect::GOOGLE_RESPONSE_JSON_SCHEMA],
            'ollama' => ['ollama', StructuredOutputDialect::OLLAMA_FORMAT],
            'anthropic' => ['anthropic', StructuredOutputDialect::ANTHROPIC_TOOL_FORCING],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dialectMapping')]
    public function testDialectMapping(string $provider, StructuredOutputDialect $expected): void
    {
        self::assertSame($expected, $this->capability->dialect($provider));
        self::assertTrue($this->capability->supports($provider, null, false));
    }

    /**
     * The Anthropic dialect IS a forced tool call, and these models answer a
     * forced `tool_choice` with a 400 — so structured output is unavailable on
     * them and the caller has to keep the prose-instruction path.
     *
     * @return array<string, array{0: string}>
     */
    public static function anthropicModelsRejectingForcedToolChoice(): array
    {
        return [
            'fable 5.1' => ['claude-fable-5-1'],
            'fable 5.1 dated alias' => ['claude-fable-5-1-20260812'],
            'mythos 5.1' => ['claude-mythos-5-1'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('anthropicModelsRejectingForcedToolChoice')]
    public function testAnthropicModelsThatRejectForcedToolChoiceAreUnsupported(string $model): void
    {
        self::assertFalse($this->capability->supports('anthropic', $model, false));
        self::assertFalse($this->capability->supports('anthropic', $model, true));
    }

    public function testOtherAnthropicModelsKeepStructuredOutput(): void
    {
        self::assertTrue($this->capability->supports('anthropic', 'claude-sonnet-5', false));
        self::assertTrue($this->capability->supports('anthropic', 'claude-fable-5', false));
    }

    /**
     * The restriction is about FORCING a tool, not about tools as such: the
     * routing hand-off path sends `tool_choice: auto` and stays available.
     */
    public function testTheRestrictionDoesNotLeakIntoNativeToolCalling(): void
    {
        self::assertTrue((new \App\AI\ToolCalling\ToolCallingCapability())->supports('anthropic', 'claude-fable-5-1', false));
    }

    public function testUnknownProviderHasNoDialect(): void
    {
        self::assertNull($this->capability->dialect('some-future-provider'));
        self::assertFalse($this->capability->supports('some-future-provider', null, false));
    }

    public function testProviderNameIsCaseInsensitive(): void
    {
        self::assertSame(StructuredOutputDialect::OPENAI_JSON_SCHEMA, $this->capability->dialect('Groq'));
        self::assertTrue($this->capability->supports('GROQ', null, false));
    }
}
