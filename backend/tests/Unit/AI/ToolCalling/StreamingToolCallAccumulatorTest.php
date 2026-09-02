<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\ToolCalling;

use App\AI\ToolCalling\StreamingToolCallAccumulator;
use PHPUnit\Framework\TestCase;

final class StreamingToolCallAccumulatorTest extends TestCase
{
    public function testOpenAiArgumentFragmentsAreConcatenatedIntoOneCall(): void
    {
        $accumulator = new StreamingToolCallAccumulator();

        $accumulator->pushOpenAiChunk(['choices' => [['delta' => ['tool_calls' => [[
            'index' => 0,
            'id' => 'call_1',
            'function' => ['name' => 'handoff_mediamaker', 'arguments' => ''],
        ]]]]]]);
        $accumulator->pushOpenAiChunk(['choices' => [['delta' => ['tool_calls' => [[
            'index' => 0,
            'function' => ['arguments' => '{"media_'],
        ]]]]]]);
        $accumulator->pushOpenAiChunk(['choices' => [['delta' => ['tool_calls' => [[
            'index' => 0,
            'function' => ['arguments' => 'type":"image"}'],
        ]]]]]]);

        $calls = $accumulator->toolCalls();

        self::assertTrue($accumulator->hasToolCalls());
        self::assertCount(1, $calls);
        self::assertSame('call_1', $calls[0]->id);
        self::assertSame('handoff_mediamaker', $calls[0]->name);
        self::assertSame(['media_type' => 'image'], $calls[0]->arguments);
    }

    public function testParallelOpenAiCallsAreKeptApartByTheirIndex(): void
    {
        $accumulator = new StreamingToolCallAccumulator();

        $accumulator->pushOpenAiChunk(['choices' => [['delta' => ['tool_calls' => [
            ['index' => 0, 'id' => 'a', 'function' => ['name' => 'handoff_mediamaker', 'arguments' => '{"media_type":']],
            ['index' => 1, 'id' => 'b', 'function' => ['name' => 'handoff_officemaker', 'arguments' => '{']],
        ]]]]]);
        $accumulator->pushOpenAiChunk(['choices' => [['delta' => ['tool_calls' => [
            ['index' => 0, 'function' => ['arguments' => '"audio"}']],
            ['index' => 1, 'function' => ['arguments' => '}']],
        ]]]]]);

        $calls = $accumulator->toolCalls();

        self::assertCount(2, $calls);
        self::assertSame(['media_type' => 'audio'], $calls[0]->arguments);
        self::assertSame('handoff_officemaker', $calls[1]->name);
    }

    public function testAStreamWithoutToolCallsAccumulatesNothing(): void
    {
        $accumulator = new StreamingToolCallAccumulator();

        $accumulator->pushOpenAiChunk(['choices' => [['delta' => ['content' => 'Paris']]]]);
        $accumulator->pushOpenAiChunk(['usage' => ['total_tokens' => 12]]);

        self::assertFalse($accumulator->hasToolCalls());
        self::assertSame([], $accumulator->toolCalls());
    }

    public function testAnthropicToolUseBlockIsBuiltFromItsStartAndItsJsonDeltas(): void
    {
        $accumulator = new StreamingToolCallAccumulator();

        $accumulator->pushAnthropicEvent([
            'type' => 'content_block_start',
            'index' => 1,
            'content_block' => ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'handoff_docsummary'],
        ]);
        $accumulator->pushAnthropicEvent([
            'type' => 'content_block_delta',
            'index' => 1,
            'delta' => ['type' => 'input_json_delta', 'partial_json' => '{}'],
        ]);

        $calls = $accumulator->toolCalls();

        self::assertCount(1, $calls);
        self::assertSame('handoff_docsummary', $calls[0]->name);
        self::assertSame([], $calls[0]->arguments);
    }

    /**
     * Text and tool blocks share one index space, so a text block must not
     * create a phantom call.
     */
    public function testAnthropicTextBlocksAreIgnored(): void
    {
        $accumulator = new StreamingToolCallAccumulator();

        $accumulator->pushAnthropicEvent([
            'type' => 'content_block_start',
            'index' => 0,
            'content_block' => ['type' => 'text', 'text' => ''],
        ]);
        $accumulator->pushAnthropicEvent([
            'type' => 'content_block_delta',
            'index' => 0,
            'delta' => ['type' => 'text_delta', 'text' => 'Paris'],
        ]);

        self::assertFalse($accumulator->hasToolCalls());
    }

    public function testAnthropicJsonDeltaWithoutAPrecedingToolBlockIsIgnored(): void
    {
        $accumulator = new StreamingToolCallAccumulator();

        $accumulator->pushAnthropicEvent([
            'type' => 'content_block_delta',
            'index' => 7,
            'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"a":1}'],
        ]);

        self::assertFalse($accumulator->hasToolCalls());
    }
}
