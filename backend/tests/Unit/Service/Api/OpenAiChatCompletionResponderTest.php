<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Api;

use App\Service\Api\OpenAiChatCompletionResponder;
use PHPUnit\Framework\TestCase;

final class OpenAiChatCompletionResponderTest extends TestCase
{
    public function testNonStreamToolCallsNullContent(): void
    {
        $payload = OpenAiChatCompletionResponder::nonStreamPayload('chatcmpl-x', 1700000000, 'test-model', [
            'content' => '',
            'tool_calls' => [[
                'id' => 'call_test_1',
                'type' => 'function',
                'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Berlin"}'],
            ]],
            'finish_reason' => 'tool_calls',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 8, 'total_tokens' => 18],
        ]);

        self::assertNull($payload['choices'][0]['message']['content']);
        self::assertSame('tool_calls', $payload['choices'][0]['finish_reason']);
        self::assertSame('get_weather', $payload['choices'][0]['message']['tool_calls'][0]['function']['name']);
        self::assertSame(18, $payload['usage']['total_tokens']);
    }

    public function testNonStreamTextKeepsStringContent(): void
    {
        $payload = OpenAiChatCompletionResponder::nonStreamPayload('chatcmpl-x', 1, 'gpt-4o', [
            'content' => 'Hello!',
        ]);

        self::assertSame('Hello!', $payload['choices'][0]['message']['content']);
        self::assertSame('stop', $payload['choices'][0]['finish_reason']);
        self::assertArrayNotHasKey('tool_calls', $payload['choices'][0]['message']);
    }

    public function testMeteringNotesEachToolCall(): void
    {
        $text = OpenAiChatCompletionResponder::responseTextForMetering('', [
            [
                'id' => 'call_1',
                'type' => 'function',
                'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Berlin"}'],
            ],
        ]);

        self::assertSame('[tool_call get_weather({"city":"Berlin"})]', $text);
    }

    public function testToolCallDeltaFirstChunkCarriesIdTypeName(): void
    {
        $announced = [];
        $first = OpenAiChatCompletionResponder::toolCallDeltaChunk(
            'chatcmpl-fixed',
            1700000000,
            'test-model',
            [
                'type' => 'tool_call_delta',
                'index' => 0,
                'id' => 'call_test_1',
                'name' => 'get_weather',
                'arguments' => '{"city":"',
            ],
            $announced,
        );
        $second = OpenAiChatCompletionResponder::toolCallDeltaChunk(
            'chatcmpl-fixed',
            1700000000,
            'test-model',
            [
                'type' => 'tool_call_delta',
                'index' => 0,
                'id' => null,
                'name' => null,
                'arguments' => 'Berlin"}',
            ],
            $announced,
        );

        $firstCall = $first['choices'][0]['delta']['tool_calls'][0];
        self::assertSame(0, $firstCall['index']);
        self::assertSame('call_test_1', $firstCall['id']);
        self::assertSame('function', $firstCall['type']);
        self::assertSame('get_weather', $firstCall['function']['name']);
        self::assertSame('{"city":"', $firstCall['function']['arguments']);

        $secondCall = $second['choices'][0]['delta']['tool_calls'][0];
        self::assertSame(0, $secondCall['index']);
        self::assertArrayNotHasKey('id', $secondCall);
        self::assertArrayNotHasKey('type', $secondCall);
        self::assertArrayNotHasKey('name', $secondCall['function']);
        self::assertSame('Berlin"}', $secondCall['function']['arguments']);
    }

    public function testGoldenSseMatchesFixture(): void
    {
        $announced = [];
        $events = [
            OpenAiChatCompletionResponder::roleChunk('chatcmpl-fixed', 1700000000, 'test-model'),
            OpenAiChatCompletionResponder::toolCallDeltaChunk(
                'chatcmpl-fixed',
                1700000000,
                'test-model',
                [
                    'type' => 'tool_call_delta',
                    'index' => 0,
                    'id' => 'call_test_1',
                    'name' => 'get_weather',
                    'arguments' => '{"city":"',
                ],
                $announced,
            ),
            OpenAiChatCompletionResponder::toolCallDeltaChunk(
                'chatcmpl-fixed',
                1700000000,
                'test-model',
                [
                    'type' => 'tool_call_delta',
                    'index' => 0,
                    'arguments' => 'Berlin"}',
                ],
                $announced,
            ),
            OpenAiChatCompletionResponder::finishChunk('chatcmpl-fixed', 1700000000, 'test-model', 'tool_calls'),
        ];

        $sse = '';
        foreach ($events as $event) {
            $sse .= 'data: '.json_encode($event, JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
        }
        $sse .= "data: [DONE]\n\n";

        $fixture = file_get_contents(__DIR__.'/../../../Fixtures/openai-compatible/tools/stream_tool_calls.sse');
        self::assertNotFalse($fixture);
        self::assertSame($fixture, $sse);
    }

    public function testIncludeUsageChunk(): void
    {
        $chunk = OpenAiChatCompletionResponder::usageChunk('chatcmpl-fixed', 1700000000, 'test-model', [
            'prompt_tokens' => 10,
            'completion_tokens' => 8,
            'total_tokens' => 18,
        ]);

        self::assertSame([], $chunk['choices']);
        self::assertSame(18, $chunk['usage']['total_tokens']);
    }
}
