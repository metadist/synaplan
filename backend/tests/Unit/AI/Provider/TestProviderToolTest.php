<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider;

use App\AI\Provider\TestProvider;
use App\AI\Tool\ToolCallAccumulator;
use PHPUnit\Framework\TestCase;

final class TestProviderToolTest extends TestCase
{
    public function testSupportsToolCallingForTestModelOnly(): void
    {
        $provider = new TestProvider();
        self::assertTrue($provider->supportsToolCalling('test-model'));
        self::assertFalse($provider->supportsToolCalling('test-vectorize'));
    }

    public function testTooltestPromptReturnsToolCall(): void
    {
        $result = (new TestProvider())->chat(
            [['role' => 'user', 'content' => 'TOOLTEST:web_search:{"q":"synaplan"}']],
            [
                'model' => 'test-model',
                'tools' => [[
                    'type' => 'function',
                    'function' => ['name' => 'web_search', 'parameters' => ['type' => 'object']],
                ]],
            ]
        );

        self::assertSame('tool_calls', $result['finish_reason']);
        self::assertSame('', $result['content']);
        self::assertSame('web_search', $result['tool_calls'][0]['function']['name']);
        self::assertSame('{"q":"synaplan"}', $result['tool_calls'][0]['function']['arguments']);
        self::assertSame('call_test_1', $result['tool_calls'][0]['id']);
    }

    public function testMcpTooltestPromptReturnsToolCall(): void
    {
        $result = (new TestProvider())->chat(
            [['role' => 'user', 'content' => 'TOOLTEST:mcp:{"server":"1","tool":"rag_search"}']],
            [
                'model' => 'test-model',
                'tools' => [[
                    'type' => 'function',
                    'function' => ['name' => 'mcp', 'parameters' => ['type' => 'object']],
                ]],
            ]
        );

        self::assertSame('mcp', $result['tool_calls'][0]['function']['name']);
    }

    public function testToolResultMessageReturnsAcknowledgement(): void
    {
        $result = (new TestProvider())->chat(
            [
                ['role' => 'user', 'content' => 'TOOLTEST:web_search:{"q":"x"}'],
                [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'call_test_1',
                        'type' => 'function',
                        'function' => ['name' => 'web_search', 'arguments' => '{"q":"x"}'],
                    ]],
                ],
                ['role' => 'tool', 'tool_call_id' => 'call_test_1', 'content' => 'hit'],
            ],
            ['model' => 'test-model']
        );

        self::assertSame('Tool result received: hit', $result['content']);
        self::assertArrayNotHasKey('tool_calls', $result);
    }

    public function testStreamSplitsToolCallDeltasThenFinishes(): void
    {
        $chunks = [];
        (new TestProvider())->chatStream(
            [['role' => 'user', 'content' => 'TOOLTEST:lookup:{"id":12}']],
            static function (mixed $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            },
            [
                'model' => 'test-model',
                'tools' => [[
                    'type' => 'function',
                    'function' => ['name' => 'lookup', 'parameters' => ['type' => 'object']],
                ]],
            ]
        );

        self::assertSame('tool_call_delta', $chunks[0]['type']);
        self::assertSame('tool_call_delta', $chunks[1]['type']);
        self::assertSame('finish', $chunks[2]['type']);
        self::assertSame('tool_calls', $chunks[2]['finish_reason']);

        $acc = new ToolCallAccumulator();
        $acc->addDelta($chunks[0]);
        $acc->addDelta($chunks[1]);
        $calls = $acc->complete();
        self::assertSame('lookup', $calls[0]['function']['name']);
        self::assertSame('{"id":12}', $calls[0]['function']['arguments']);
    }

    public function testWithoutToolsOptionTooltestIsOrdinaryText(): void
    {
        $result = (new TestProvider())->chat(
            [['role' => 'user', 'content' => 'TOOLTEST:web_search:{"q":"x"}']],
            ['model' => 'test-model']
        );

        self::assertArrayNotHasKey('tool_calls', $result);
        self::assertNotSame('', $result['content']);
    }
}
