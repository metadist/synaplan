<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Tool;

use App\AI\Tool\ToolCallAccumulator;
use PHPUnit\Framework\TestCase;

final class ToolCallAccumulatorTest extends TestCase
{
    public function testFoldsDeltasByIndexAndRepairsEmptyArguments(): void
    {
        $acc = new ToolCallAccumulator();
        $acc->addDelta([
            'type' => 'tool_call_delta',
            'index' => 0,
            'id' => 'call_1',
            'name' => 'web_search',
            'arguments' => '{"q":',
        ]);
        $acc->addDelta([
            'type' => 'tool_call_delta',
            'index' => 0,
            'arguments' => '"synaplan"}',
        ]);
        $acc->addDelta([
            'type' => 'tool_call_delta',
            'index' => 1,
            'name' => 'empty_tool',
            'arguments' => '',
        ]);

        $calls = $acc->complete();
        self::assertCount(2, $calls);
        self::assertSame('call_1', $calls[0]['id']);
        self::assertSame('web_search', $calls[0]['function']['name']);
        self::assertSame('{"q":"synaplan"}', $calls[0]['function']['arguments']);
        self::assertSame('{}', $calls[1]['function']['arguments']);
        self::assertStringStartsWith('call_', $calls[1]['id']);
        self::assertSame('function', $calls[1]['type']);
    }

    public function testGeneratesIdWhenUpstreamOmitsIt(): void
    {
        $acc = new ToolCallAccumulator();
        $acc->addDelta([
            'type' => 'tool_call_delta',
            'index' => 0,
            'name' => 'lookup',
            'arguments' => '{"id":1}',
        ]);

        $calls = $acc->complete();
        self::assertCount(1, $calls);
        self::assertMatchesRegularExpression('/^call_[0-9a-f]{12}$/', $calls[0]['id']);
    }

    public function testRepairsInvalidJsonArguments(): void
    {
        $acc = new ToolCallAccumulator();
        $acc->addDelta([
            'type' => 'tool_call_delta',
            'index' => 0,
            'id' => 'call_x',
            'name' => 'broken',
            'arguments' => '{not-json',
        ]);

        $calls = $acc->complete();
        self::assertSame('{}', $calls[0]['function']['arguments']);
    }

    public function testIgnoresNonDeltaChunks(): void
    {
        $acc = new ToolCallAccumulator();
        $acc->addDelta(['type' => 'content', 'content' => 'hi']);
        self::assertTrue($acc->isEmpty());
        self::assertSame([], $acc->complete());
    }
}
