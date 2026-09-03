<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\ToolCalling;

use App\AI\ToolCalling\ToolCallingCapability;
use App\AI\ToolCalling\ToolCallingTranslator;
use App\AI\ToolCalling\ToolDefinition;
use PHPUnit\Framework\TestCase;

final class ToolCallingTranslatorTest extends TestCase
{
    private ToolCallingTranslator $translator;

    protected function setUp(): void
    {
        $this->translator = new ToolCallingTranslator(new ToolCallingCapability());
    }

    private function tool(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'handoff_mediamaker',
            description: 'Generate an image, video or audio clip.',
            parameters: [
                'type' => 'object',
                'properties' => ['media_type' => ['type' => 'string', 'enum' => ['image', 'video', 'audio']]],
                'required' => [],
            ],
        );
    }

    /**
     * One wire shape for every provider: OpenAI's function envelope, which
     * each provider maps into its own dialect on the way out.
     */
    public function testEachToolIsWrappedInAnOpenAiFunctionEnvelope(): void
    {
        $params = $this->translator->translate('groq', 'openai/gpt-oss-120b', false, [$this->tool()]);

        self::assertSame([
            'tools' => [[
                'type' => 'function',
                'function' => [
                    'name' => 'handoff_mediamaker',
                    'description' => 'Generate an image, video or audio clip.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ['media_type' => ['type' => 'string', 'enum' => ['image', 'video', 'audio']]],
                        'required' => [],
                    ],
                ],
            ]],
            'tool_choice' => 'auto',
        ], $params);
    }

    public function testAnthropicGetsTheSameWireShapeAndConvertsItItself(): void
    {
        self::assertSame(
            $this->translator->translate('groq', 'openai/gpt-oss-120b', false, [$this->tool()]),
            $this->translator->translate('anthropic', 'claude-sonnet-5', false, [$this->tool()]),
        );
    }

    /**
     * `auto`, never `required`: "called nothing" is the answer that means
     * "ordinary chat turn", so forcing a call would invert the default.
     */
    public function testToolChoiceIsNeverForced(): void
    {
        self::assertSame('auto', $this->translator->translate('groq', 'm', false, [$this->tool()])['tool_choice']);
        self::assertSame('auto', $this->translator->translate('anthropic', 'm', false, [$this->tool()])['tool_choice']);
    }

    /**
     * An unsupported provider yields no parameters rather than an exception:
     * the caller then keeps the AI-sorter round-trip.
     */
    public function testUnsupportedProviderYieldsNoRequestParametersInsteadOfFailing(): void
    {
        self::assertSame([], $this->translator->translate('openai', 'gpt-5', false, [$this->tool()]));
        self::assertSame([], $this->translator->translate('triton', null, false, [$this->tool()]));
    }

    public function testNoToolsYieldsNoRequestParameters(): void
    {
        self::assertSame([], $this->translator->translate('groq', 'openai/gpt-oss-120b', false, []));
    }
}
