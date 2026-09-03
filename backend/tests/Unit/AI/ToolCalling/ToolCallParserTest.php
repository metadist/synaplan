<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\ToolCalling;

use App\AI\ToolCalling\ToolCallParser;
use PHPUnit\Framework\TestCase;

final class ToolCallParserTest extends TestCase
{
    private ToolCallParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ToolCallParser();
    }

    public function testWireToolCallsAreFlattenedAndTheirArgumentStringDecoded(): void
    {
        $calls = $this->parser->fromWireToolCalls([[
            'id' => 'call_abc',
            'type' => 'function',
            'function' => [
                'name' => 'handoff_mediamaker',
                'arguments' => '{"media_type":"image"}',
            ],
        ]]);

        self::assertCount(1, $calls);
        self::assertSame('call_abc', $calls[0]->id);
        self::assertSame('handoff_mediamaker', $calls[0]->name);
        self::assertSame(['media_type' => 'image'], $calls[0]->arguments);
    }

    /**
     * Anthropic and Gemini responses reach this class already mapped into the
     * OpenAI wire shape by their providers, decoded arguments included.
     */
    public function testAlreadyDecodedArgumentsArePassedThrough(): void
    {
        $calls = $this->parser->fromWireToolCalls([[
            'id' => 'toolu_1',
            'type' => 'function',
            'function' => [
                'name' => 'handoff_mediamaker',
                'arguments' => ['media_type' => 'video', 'resolution' => '1080p'],
            ],
        ]]);

        self::assertCount(1, $calls);
        self::assertSame('toolu_1', $calls[0]->id);
        self::assertSame(['media_type' => 'video', 'resolution' => '1080p'], $calls[0]->arguments);
    }

    /**
     * A truncated argument blob must not lose the fact that the tool was
     * called — the routing decision is in the NAME, and every argument is
     * optional and re-derivable downstream.
     */
    public function testMalformedArgumentsStillProduceACallWithEmptyArguments(): void
    {
        $calls = $this->parser->fromWireToolCalls([[
            'id' => 'call_1',
            'function' => ['name' => 'handoff_officemaker', 'arguments' => '{"media_ty'],
        ]]);

        self::assertCount(1, $calls);
        self::assertSame('handoff_officemaker', $calls[0]->name);
        self::assertSame([], $calls[0]->arguments);
    }

    public function testAnOrdinaryAnswerYieldsNoToolCalls(): void
    {
        self::assertSame([], $this->parser->fromWireToolCalls([]));
        self::assertSame([], $this->parser->fromWireToolCalls(null));
    }

    public function testAStructurallyUnexpectedResponseIsEmptyRatherThanFatal(): void
    {
        self::assertSame([], $this->parser->fromWireToolCalls('nope'));
        self::assertSame([], $this->parser->fromWireToolCalls(['nope']));
        self::assertSame([], $this->parser->fromWireToolCalls([['function' => 'nope']]));
    }

    public function testCallsWithoutAUsableNameAreDropped(): void
    {
        self::assertSame([], $this->parser->fromWireToolCalls([
            ['id' => 'x', 'function' => ['arguments' => '{}']],
        ]));
        self::assertSame([], $this->parser->fromWireToolCalls([
            ['id' => 'x', 'function' => ['name' => '', 'arguments' => '{}']],
        ]));
    }
}
