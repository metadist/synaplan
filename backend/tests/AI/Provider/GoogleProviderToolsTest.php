<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Provider\GoogleProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GoogleProviderToolsTest extends TestCase
{
    public function testSupportsToolCallingFollowsCatalog(): void
    {
        $provider = $this->makeProvider(new MockHttpClient());

        self::assertTrue($provider->supportsToolCalling('gemini-2.5-flash'));
    }

    public function testChatReturnsToolCallsFromRecordedFixture(): void
    {
        $json = (string) file_get_contents(
            dirname(__DIR__, 2).'/Fixtures/ai/tools/google/chat_function_call.json'
        );
        $client = new MockHttpClient(static fn () => new MockResponse($json, [
            'response_headers' => ['content-type' => 'application/json'],
        ]));

        $result = $this->makeProvider($client)->chat(
            [['role' => 'user', 'content' => 'Weather in Berlin?']],
            [
                'model' => 'gemini-2.5-flash',
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
        self::assertSame('get_weather', $result['tool_calls'][0]['function']['name']);
        self::assertSame('{"city":"Berlin"}', $result['tool_calls'][0]['function']['arguments']);
        self::assertStringStartsWith('call_', $result['tool_calls'][0]['id']);
    }

    public function testChatSendsGeminiDeclarationsAndToolHistory(): void
    {
        $captured = null;
        $json = (string) file_get_contents(
            dirname(__DIR__, 2).'/Fixtures/ai/tools/google/chat_function_call.json'
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
                        'id' => 'call_weather_1',
                        'type' => 'function',
                        'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Berlin"}'],
                    ]],
                ],
                [
                    'role' => 'tool',
                    'tool_call_id' => 'call_weather_1',
                    'content' => 'sunny',
                ],
            ],
            [
                'model' => 'gemini-2.5-flash',
                'tools' => [[
                    'type' => 'function',
                    'function' => ['name' => 'get_weather', 'parameters' => ['type' => 'object']],
                ]],
                'tool_choice' => 'auto',
            ],
        );

        self::assertNotNull($captured);
        self::assertSame('get_weather', $captured['tools'][0]['functionDeclarations'][0]['name']);
        self::assertSame('AUTO', $captured['toolConfig']['functionCallingConfig']['mode']);

        $roles = array_column($captured['contents'], 'role');
        self::assertSame(['user', 'model', 'user'], $roles);
        self::assertSame('get_weather', $captured['contents'][1]['parts'][0]['functionCall']['name']);
        self::assertSame(['city' => 'Berlin'], $captured['contents'][1]['parts'][0]['functionCall']['args']);
        self::assertSame('get_weather', $captured['contents'][2]['parts'][0]['functionResponse']['name']);
        self::assertSame(['content' => 'sunny'], $captured['contents'][2]['parts'][0]['functionResponse']['response']);
    }

    public function testChatStreamEmitsWholeArgumentsDelta(): void
    {
        $sse = (string) file_get_contents(
            dirname(__DIR__, 2).'/Fixtures/ai/tools/google/stream_function_call.sse'
        );
        $client = new MockHttpClient(static fn () => new MockResponse($sse, [
            'response_headers' => ['content-type' => 'text/event-stream'],
        ]));

        $chunks = [];
        $this->makeProvider($client)->chatStream(
            [['role' => 'user', 'content' => 'Weather?']],
            static function (mixed $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            },
            [
                'model' => 'gemini-2.5-flash',
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
        self::assertCount(1, $deltas);
        self::assertSame('get_weather', $deltas[0]['name']);
        self::assertSame('{"city":"Berlin"}', $deltas[0]['arguments']);
        $finish = $chunks[array_key_last($chunks)];
        self::assertSame('finish', $finish['type']);
        self::assertSame('tool_calls', $finish['finish_reason']);
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

    private function makeProvider(MockHttpClient $httpClient): GoogleProvider
    {
        return new GoogleProvider(new NullLogger(), $httpClient, 'test-key');
    }
}
