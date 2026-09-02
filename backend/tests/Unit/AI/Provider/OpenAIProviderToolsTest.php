<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider;

use App\AI\Provider\OpenAIProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAIProviderToolsTest extends TestCase
{
    public function testSupportsToolCallingFollowsCatalog(): void
    {
        $provider = $this->provider();

        self::assertTrue($provider->supportsToolCalling('gpt-5.4'));
        self::assertTrue($provider->supportsToolCalling('gpt-4o-mini'));
    }

    public function testBuildResponsesRequestAddsToolsAndNamedChoice(): void
    {
        $provider = $this->provider();
        $method = new \ReflectionMethod($provider, 'buildResponsesRequest');

        $result = $method->invoke(
            $provider,
            [['role' => 'user', 'content' => 'Weather?']],
            'gpt-4o',
            false,
            [
                'tools' => [[
                    'type' => 'function',
                    'function' => [
                        'name' => 'get_weather',
                        'description' => 'Look up weather',
                        'parameters' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
                    ],
                ]],
                'tool_choice' => ['type' => 'function', 'function' => ['name' => 'get_weather']],
                'previous_response_id' => 'resp_keep',
            ],
        );

        self::assertSame('resp_keep', $result['previous_response_id']);
        self::assertSame('function', $result['tools'][0]['type']);
        self::assertSame('get_weather', $result['tools'][0]['name']);
        self::assertFalse($result['tools'][0]['strict']);
        self::assertSame(['type' => 'function', 'name' => 'get_weather'], $result['tool_choice']);
    }

    public function testConvertToResponsesFormatMapsToolHistory(): void
    {
        $provider = $this->provider();
        $method = new \ReflectionMethod($provider, 'convertToResponsesFormat');

        $items = $method->invoke($provider, [
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
                'content' => 'sunny, 18C',
            ],
        ]);

        self::assertSame('user', $items[0]['role']);
        self::assertSame('function_call', $items[1]['type']);
        self::assertSame('call_weather_1', $items[1]['call_id']);
        self::assertSame('get_weather', $items[1]['name']);
        self::assertSame('{"city":"Berlin"}', $items[1]['arguments']);
        self::assertSame('function_call_output', $items[2]['type']);
        self::assertSame('call_weather_1', $items[2]['call_id']);
        self::assertSame('sunny, 18C', $items[2]['output']);
    }

    public function testExtractResponsesToolCallsFromRecordedPayload(): void
    {
        $provider = $this->provider();
        $method = new \ReflectionMethod($provider, 'extractResponsesToolCalls');
        $payload = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/ai/tools/openai/chat_function_call.json'),
            true,
        );

        $calls = $method->invoke($provider, $payload);

        self::assertSame('call_weather_1', $calls[0]['id']);
        self::assertSame('get_weather', $calls[0]['function']['name']);
        self::assertSame('{"city":"Berlin"}', $calls[0]['function']['arguments']);
    }

    public function testStreamEventsEmitToolCallDeltasThenFinishToolCalls(): void
    {
        $provider = $this->provider();
        $start = new \ReflectionMethod($provider, 'emitResponsesFunctionCallStart');
        $hasFn = new \ReflectionMethod($provider, 'responsesOutputHasFunctionCall');
        $events = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/ai/tools/openai/stream_function_call.events.json'),
            true,
        );

        $chunks = [];
        $sawFunctionCall = false;
        $finishReason = null;

        foreach ($events as $event) {
            $type = $event['event'];
            $data = $event['data'];
            if ('response.output_item.added' === $type) {
                if ($start->invoke($provider, $data, static function (array $chunk) use (&$chunks): void {
                    $chunks[] = $chunk;
                })) {
                    $sawFunctionCall = true;
                }
            } elseif ('response.function_call_arguments.delta' === $type) {
                $sawFunctionCall = true;
                $chunks[] = [
                    'type' => 'tool_call_delta',
                    'index' => (int) ($data['output_index'] ?? 0),
                    'id' => null,
                    'name' => null,
                    'arguments' => $data['delta'],
                ];
            } elseif ('response.completed' === $type) {
                $finishReason = ($sawFunctionCall || $hasFn->invoke($provider, $data['response']['output'] ?? []))
                    ? 'tool_calls'
                    : 'stop';
            }
        }

        $deltas = array_values(array_filter(
            $chunks,
            static fn (array $c): bool => 'tool_call_delta' === $c['type'],
        ));
        self::assertSame('call_weather_1', $deltas[0]['id']);
        self::assertSame('get_weather', $deltas[0]['name']);
        self::assertSame('{"city":', $deltas[1]['arguments']);
        self::assertSame('"Berlin"}', $deltas[2]['arguments']);
        self::assertSame('tool_calls', $finishReason);
    }

    private function provider(): OpenAIProvider
    {
        return new OpenAIProvider(
            new NullLogger(),
            $this->createMock(HttpClientInterface::class),
            'test-key',
            '/tmp/uploads',
            false,
        );
    }
}
