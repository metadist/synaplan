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

    public function testOpenAiDialectWrapsEachToolInAFunctionEnvelope(): void
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

    public function testAnthropicDialectUsesInputSchemaAndAnObjectToolChoice(): void
    {
        $params = $this->translator->translate('anthropic', 'claude-sonnet-5', false, [$this->tool()]);

        self::assertSame('handoff_mediamaker', $params['tools'][0]['name']);
        self::assertArrayHasKey('input_schema', $params['tools'][0]);
        self::assertArrayNotHasKey('parameters', $params['tools'][0]);
        self::assertSame(['type' => 'auto'], $params['tool_choice']);
    }

    /**
     * `auto`, never `required`: "called nothing" is the answer that means
     * "ordinary chat turn", so forcing a call would invert the default.
     */
    public function testToolChoiceIsNeverForced(): void
    {
        self::assertSame('auto', $this->translator->translate('groq', 'm', false, [$this->tool()])['tool_choice']);
        self::assertSame(['type' => 'auto'], $this->translator->translate('anthropic', 'm', false, [$this->tool()])['tool_choice']);
    }

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
