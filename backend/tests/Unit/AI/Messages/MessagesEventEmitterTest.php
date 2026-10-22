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

    public function testEmitAssistantTextWhenTheUpstreamSentNothing(): void
    {
        $events = [];
        $emitter = new MessagesEventEmitter(static function (array $chunk) use (&$events): void {
            $events[] = $chunk;
        });

        $this->assertFalse($emitter->hasEmittedText());
        $emitter->emitAssistantText('I looked this up but could not turn the results into an answer.');
        $this->assertTrue($emitter->hasEmittedText());

        $types = array_map(static fn (array $e): string => (string) ($e['data']['type'] ?? ''), $events);
        $this->assertSame(
            ['message_start', 'content_block_start', 'content_block_delta', 'content_block_stop'],
            $types,
        );
        $this->assertSame(
            'I looked this up but could not turn the results into an answer.',
            $events[2]['data']['delta']['text'],
        );
    }

    public function testClientToolIsFlushedBeforeAFinalStopAndDroppedOnAMixedTurn(): void
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
            'content_block' => ['type' => 'text', 'text' => ''],
        ], isFinalTurn: false);
        $emitter->relay('content_block_delta', [
            'type' => 'content_block_delta',
            'index' => 0,
            'delta' => ['type' => 'text_delta', 'text' => 'Reading the page.'],
        ], isFinalTurn: false);
        $emitter->relay('content_block_start', [
            'type' => 'content_block_start',
            'index' => 1,
            'content_block' => ['type' => 'tool_use', 'id' => 'toolu_fetch', 'name' => 'web_fetch', 'input' => []],
        ], isFinalTurn: false, suppressToolNames: ['web_fetch']);
        $emitter->relay('content_block_start', [
            'type' => 'content_block_start',
            'index' => 2,
            'content_block' => ['type' => 'tool_use', 'id' => 'toolu_write', 'name' => 'write_file', 'input' => []],
        ], isFinalTurn: false, suppressToolNames: ['web_fetch']);
        $emitter->relay('content_block_delta', [
            'type' => 'content_block_delta',
            'index' => 2,
            'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"path":"notes.md"}'],
        ], isFinalTurn: false, suppressToolNames: ['web_fetch']);
        $emitter->relay('content_block_stop', [
            'type' => 'content_block_stop',
            'index' => 2,
        ], isFinalTurn: false, suppressToolNames: ['web_fetch']);

        $beforeStop = array_map(static fn (array $e): string => (string) ($e['data']['type'] ?? ''), $events);
        $this->assertSame(['message_start', 'content_block_start', 'content_block_delta'], $beforeStop);

        $emitter->relay('message_delta', [
            'type' => 'message_delta',
            'delta' => ['stop_reason' => 'tool_use'],
        ], isFinalTurn: false, suppressToolNames: ['web_fetch']);

        $afterMixed = array_map(static fn (array $e): string => (string) ($e['data']['content_block']['name'] ?? ''), $events);
        $this->assertNotContains('write_file', $afterMixed);
        $this->assertNotContains('web_fetch', $afterMixed);

        $emitter->resetTurnMapping();
        $emitter->relay('content_block_start', [
            'type' => 'content_block_start',
            'index' => 0,
            'content_block' => ['type' => 'tool_use', 'id' => 'toolu_write', 'name' => 'write_file', 'input' => []],
        ], isFinalTurn: false, suppressToolNames: ['web_fetch']);
        $emitter->relay('content_block_delta', [
            'type' => 'content_block_delta',
            'index' => 0,
            'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"path":"notes.md","content":"Node.js 24"}'],
        ], isFinalTurn: false, suppressToolNames: ['web_fetch']);
        $emitter->relay('content_block_stop', [
            'type' => 'content_block_stop',
            'index' => 0,
        ], isFinalTurn: false, suppressToolNames: ['web_fetch']);
        $emitter->relay('message_delta', [
            'type' => 'message_delta',
            'delta' => ['stop_reason' => 'tool_use'],
        ], isFinalTurn: true, suppressToolNames: ['web_fetch']);
        $emitter->relay('message_stop', ['type' => 'message_stop'], isFinalTurn: true);

        $names = [];
        foreach ($events as $event) {
            $name = $event['data']['content_block']['name'] ?? null;
            if (\is_string($name)) {
                $names[] = $name;
            }
        }
        $this->assertSame(['write_file'], $names);
        $types = array_map(static fn (array $e): string => (string) ($e['data']['type'] ?? ''), $events);
        $this->assertSame('message_delta', $types[array_key_last($types) - 1]);
        $this->assertSame('message_stop', $types[array_key_last($types)]);
        $writeIndex = null;
        foreach ($events as $event) {
            if ('write_file' === ($event['data']['content_block']['name'] ?? null)) {
                $writeIndex = $event['data']['index'];
            }
        }
        $this->assertSame(1, $writeIndex);
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
