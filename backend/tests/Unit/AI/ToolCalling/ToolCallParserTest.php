<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\ToolCalling;

use App\AI\ToolCalling\ToolCallingDialect;
use App\AI\ToolCalling\ToolCallParser;
use PHPUnit\Framework\TestCase;

final class ToolCallParserTest extends TestCase
{
    private ToolCallParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ToolCallParser();
    }

    public function testOpenAiToolCallsAreFlattenedAndTheirArgumentStringDecoded(): void
    {
        $calls = $this->parser->parse(ToolCallingDialect::OPENAI_FUNCTIONS, [
            'choices' => [[
                'message' => [
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_abc',
                        'type' => 'function',
                        'function' => [
                            'name' => 'handoff_mediamaker',
                            'arguments' => '{"media_type":"image"}',
                        ],
                    ]],
                ],
            ]],
        ]);

        self::assertCount(1, $calls);
        self::assertSame('call_abc', $calls[0]->id);
        self::assertSame('handoff_mediamaker', $calls[0]->name);
        self::assertSame(['media_type' => 'image'], $calls[0]->arguments);
    }

    /**
     * A truncated argument blob must not lose the fact that the tool was
     * called — the routing decision is in the NAME, and every argument is
     * optional and re-derivable downstream.
     */
    public function testMalformedOpenAiArgumentsStillProduceACallWithEmptyArguments(): void
    {
        $calls = $this->parser->parse(ToolCallingDialect::OPENAI_FUNCTIONS, [
            'choices' => [['message' => ['tool_calls' => [[
                'id' => 'call_1',
                'function' => ['name' => 'handoff_officemaker', 'arguments' => '{"media_ty'],
            ]]]]],
        ]);

        self::assertCount(1, $calls);
        self::assertSame('handoff_officemaker', $calls[0]->name);
        self::assertSame([], $calls[0]->arguments);
    }

    public function testAnthropicToolUseBlocksAreReadWithTheirDecodedInput(): void
    {
        $calls = $this->parser->parse(ToolCallingDialect::ANTHROPIC_TOOLS, [
            'content' => [
                ['type' => 'text', 'text' => 'Sure, generating that now.'],
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'handoff_mediamaker',
                    'input' => ['media_type' => 'video', 'resolution' => '1080p'],
                ],
            ],
        ]);

        self::assertCount(1, $calls);
        self::assertSame('toolu_1', $calls[0]->id);
        self::assertSame(['media_type' => 'video', 'resolution' => '1080p'], $calls[0]->arguments);
    }

    public function testAnOrdinaryAnswerYieldsNoToolCalls(): void
    {
        self::assertSame([], $this->parser->parse(ToolCallingDialect::OPENAI_FUNCTIONS, [
            'choices' => [['message' => ['content' => 'Paris.']]],
        ]));
        self::assertSame([], $this->parser->parse(ToolCallingDialect::ANTHROPIC_TOOLS, [
            'content' => [['type' => 'text', 'text' => 'Paris.']],
        ]));
    }

    public function testAStructurallyUnexpectedResponseIsEmptyRatherThanFatal(): void
    {
        self::assertSame([], $this->parser->parse(ToolCallingDialect::OPENAI_FUNCTIONS, []));
        self::assertSame([], $this->parser->parse(ToolCallingDialect::OPENAI_FUNCTIONS, ['choices' => 'nope']));
        self::assertSame([], $this->parser->parse(ToolCallingDialect::ANTHROPIC_TOOLS, ['content' => 'nope']));
    }

    public function testCallsWithoutAUsableNameAreDropped(): void
    {
        self::assertSame([], $this->parser->parse(ToolCallingDialect::OPENAI_FUNCTIONS, [
            'choices' => [['message' => ['tool_calls' => [['id' => 'x', 'function' => ['arguments' => '{}']]]]]],
        ]));
        self::assertSame([], $this->parser->parse(ToolCallingDialect::ANTHROPIC_TOOLS, [
            'content' => [['type' => 'tool_use', 'id' => 'x', 'input' => []]],
        ]));
    }
}
