<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\MessagesEventEmitter;
use PHPUnit\Framework\TestCase;

final class MessagesEventEmitterTest extends TestCase
{
    public function testRemapsIndicesAndSuppressesIntermediateStops(): void
    {
        $events = [];
        $emitter = new MessagesEventEmitter(static function (array $chunk) use (&$events): void {
            $events[] = $chunk;
        });

        $emitter->relay('message_start', [
            'type' => 'message_start',
            'message' => ['id' => 'm1', 'role' => 'assistant'],
        ], isFinalTurn: false);

        $emitter->relay('content_block_start', [
            'type' => 'content_block_start',
            'index' => 0,
            'content_block' => ['type' => 'tool_use', 'id' => 't1', 'name' => 'mcp__1__rag_search', 'input' => []],
        ], isFinalTurn: false, suppressToolNames: ['mcp__1__rag_search']);

        $emitter->relay('message_delta', [
            'type' => 'message_delta',
            'delta' => ['stop_reason' => 'tool_use'],
        ], isFinalTurn: false);

        $emitter->relay('message_stop', ['type' => 'message_stop'], isFinalTurn: false);

        $emitter->resetTurnMapping();

        $emitter->relay('message_start', [
            'type' => 'message_start',
            'message' => ['id' => 'm2'],
        ], isFinalTurn: true);

        $emitter->relay('content_block_start', [
            'type' => 'content_block_start',
            'index' => 0,
            'content_block' => ['type' => 'text', 'text' => ''],
        ], isFinalTurn: true);

        $emitter->relay('content_block_delta', [
            'type' => 'content_block_delta',
            'index' => 0,
            'delta' => ['type' => 'text_delta', 'text' => 'hi'],
        ], isFinalTurn: true);

        $emitter->relay('message_delta', [
            'type' => 'message_delta',
            'delta' => ['stop_reason' => 'end_turn'],
            'usage' => ['output_tokens' => 3],
        ], isFinalTurn: true);

        $emitter->relay('message_stop', ['type' => 'message_stop'], isFinalTurn: true);

        $types = array_map(static fn (array $e): string => (string) ($e['data']['type'] ?? ''), $events);

        $this->assertSame(
            ['message_start', 'content_block_start', 'content_block_delta', 'message_delta', 'message_stop'],
            $types,
        );
        $this->assertSame(0, $events[1]['data']['index']);
        $this->assertSame(0, $events[2]['data']['index']);
    }

    public function testEmitPing(): void
    {
        $events = [];
        $emitter = new MessagesEventEmitter(static function (array $chunk) use (&$events): void {
            $events[] = $chunk;
        });
        $emitter->emitPing();
        $this->assertSame('ping', $events[0]['event']);
        $this->assertSame('ping', $events[0]['data']['type']);
    }
}
