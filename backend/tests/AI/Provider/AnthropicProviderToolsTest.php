<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Provider\AnthropicProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AnthropicProviderToolsTest extends TestCase
{
    public function testSupportsToolCallingFollowsCatalog(): void
    {
        $provider = $this->makeProvider(new MockHttpClient());

        self::assertTrue($provider->supportsToolCalling('claude-sonnet-5'));
    }

    public function testChatReturnsToolCallsFromRecordedFixture(): void
    {
        $json = (string) file_get_contents(
            dirname(__DIR__, 2).'/Fixtures/ai/tools/anthropic/chat_tool_use.json'
        );
        $client = new MockHttpClient(static fn () => new MockResponse($json, [
            'response_headers' => ['content-type' => 'application/json'],
        ]));

        $result = $this->makeProvider($client)->chat(
            [['role' => 'user', 'content' => 'Weather in Berlin?']],
            [
                'model' => 'claude-sonnet-4-6',
                'tools' => [[
                    'type' => 'function',
                    'function' => [
                        'name' => 'get_weather',
                        'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
                    ],
                ]],
            ],
        );

        self::assertSame('', $result['content']);
        self::assertSame('tool_calls', $result['finish_reason']);
        self::assertSame('toolu_weather_1', $result['tool_calls'][0]['id']);
        self::assertSame('get_weather', $result['tool_calls'][0]['function']['name']);
        self::assertSame('{"city":"Berlin"}', $result['tool_calls'][0]['function']['arguments']);
    }

    public function testChatSendsAnthropicToolsAndToolHistory(): void
    {
        $captured = null;
        $json = (string) file_get_contents(
            dirname(__DIR__, 2).'/Fixtures/ai/tools/anthropic/chat_tool_use.json'
        );
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured, $json): MockResponse {
            $captured = $this->decodeRequestBody($options);

            return new MockResponse($json, [
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        });

        $this->makeProvider($client)->chat(
            [
                ['role' => 'user', 'content' => 'Weather in Berlin?'],
                [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [[
                        'id' => 'toolu_weather_1',
                        'type' => 'function',
                        'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Berlin"}'],
                    ]],
                ],
                [
                    'role' => 'tool',
                    'tool_call_id' => 'toolu_weather_1',
                    'content' => 'sunny',
                ],
            ],
            [
                'model' => 'claude-sonnet-4-6',
                'tools' => [[
                    'type' => 'function',
                    'function' => ['name' => 'get_weather', 'parameters' => ['type' => 'object']],
                ]],
                'tool_choice' => 'auto',
            ],
        );

        self::assertNotNull($captured);
        self::assertSame('get_weather', $captured['tools'][0]['name']);
        self::assertArrayHasKey('input_schema', $captured['tools'][0]);
        self::assertSame(['type' => 'auto'], $captured['tool_choice']);

        $roles = array_column($captured['messages'], 'role');
        self::assertContains('assistant', $roles);
        self::assertContains('user', $roles);

        $assistant = null;
        $toolResult = null;
        foreach ($captured['messages'] as $msg) {
            if ('assistant' === $msg['role'] && is_array($msg['content'])) {
                foreach ($msg['content'] as $block) {
                    if ('tool_use' === ($block['type'] ?? '')) {
                        $assistant = $block;
                    }
                }
            }
            if ('user' === $msg['role'] && is_array($msg['content'])) {
                foreach ($msg['content'] as $block) {
                    if ('tool_result' === ($block['type'] ?? '')) {
                        $toolResult = $block;
                    }
                }
            }
        }

        self::assertSame('toolu_weather_1', $assistant['id'] ?? null);
        self::assertSame('get_weather', $assistant['name'] ?? null);
        self::assertSame(['city' => 'Berlin'], $assistant['input'] ?? null);
        self::assertSame('toolu_weather_1', $toolResult['tool_use_id'] ?? null);
        self::assertSame('sunny', $toolResult['content'] ?? null);
    }

    public function testChatStreamEmitsToolCallDeltas(): void
    {
        $sse = $this->buildSseStream([
            ['event' => 'message_start', 'data' => [
                'type' => 'message_start',
                'message' => ['usage' => ['input_tokens' => 10, 'output_tokens' => 0]],
            ]],
            ['event' => 'content_block_start', 'data' => [
                'type' => 'content_block_start',
                'index' => 0,
                'content_block' => [
                    'type' => 'tool_use',
                    'id' => 'toolu_weather_1',
                    'name' => 'get_weather',
                    'input' => [],
                ],
            ]],
            ['event' => 'content_block_delta', 'data' => [
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"city":'],
            ]],
            ['event' => 'content_block_delta', 'data' => [
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'input_json_delta', 'partial_json' => '"Berlin"}'],
            ]],
            ['event' => 'content_block_stop', 'data' => ['type' => 'content_block_stop', 'index' => 0]],
            ['event' => 'message_delta', 'data' => [
                'type' => 'message_delta',
                'delta' => ['stop_reason' => 'tool_use'],
                'usage' => ['output_tokens' => 8],
            ]],
            ['event' => 'message_stop', 'data' => ['type' => 'message_stop']],
        ]);

        $client = new MockHttpClient(static fn () => new MockResponse($sse, [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'text/event-stream'],
        ]));

        $chunks = [];
        $this->makeProvider($client)->chatStream(
            [['role' => 'user', 'content' => 'Weather?']],
            static function (mixed $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            },
            [
                'model' => 'claude-sonnet-4-6',
                'tools' => [[
                    'type' => 'function',
                    'function' => ['name' => 'get_weather', 'parameters' => ['type' => 'object']],
                ]],
            ],
        );

        $deltas = array_values(array_filter(
            $chunks,
            static fn (mixed $c): bool => is_array($c) && 'tool_call_delta' === ($c['type'] ?? null)
        ));
        self::assertSame('toolu_weather_1', $deltas[0]['id']);
        self::assertSame('get_weather', $deltas[0]['name']);
        self::assertSame('{"city":', $deltas[1]['arguments']);
        self::assertSame('"Berlin"}', $deltas[2]['arguments']);
        $finish = $chunks[array_key_last($chunks)];
        self::assertSame('finish', $finish['type']);
        self::assertSame('tool_calls', $finish['finish_reason']);
    }

    /**
     * @param list<array{event: string, data: array<string, mixed>}> $events
     */
    private function buildSseStream(array $events): string
    {
        $out = '';
        foreach ($events as $event) {
            $out .= 'event: '.$event['event']."\n";
            $out .= 'data: '.json_encode($event['data'])."\n\n";
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function decodeRequestBody(array $options): array
    {
        if (isset($options['json']) && is_array($options['json'])) {
            return $options['json'];
        }

        $body = $options['body'] ?? '';
        if (is_string($body) && '' !== $body) {
            $decoded = json_decode($body, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function makeProvider(MockHttpClient $httpClient): AnthropicProvider
    {
        return new AnthropicProvider($httpClient, new NullLogger(), 'test-key');
    }
}
