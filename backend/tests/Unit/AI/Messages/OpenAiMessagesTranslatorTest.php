<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Credential\OpenAiCompatibleEndpointRegistry;
use App\AI\Messages\Translator\ChatCompletionsUpstreams;
use App\AI\Messages\Translator\OpenAiMessagesTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenAiMessagesTranslatorTest extends TestCase
{
    public function testStripsThinkingAndMapsTools(): void
    {
        $t = new OpenAiMessagesTranslator(new MockHttpClient());

        $payload = $t->toOpenAiRequest([
            'model' => 'gpt-4o',
            'max_tokens' => 64,
            'thinking' => ['type' => 'adaptive'],
            'system' => 'Be brief.',
            'tools' => [[
                'name' => 'mcp__1__rag_search',
                'description' => 'search',
                'input_schema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]],
            ]],
            'messages' => [
                ['role' => 'user', 'content' => 'hi'],
                ['role' => 'assistant', 'content' => [[
                    'type' => 'tool_use',
                    'id' => 'toolu_1',
                    'name' => 'mcp__1__rag_search',
                    'input' => ['q' => 'x'],
                ]]],
                ['role' => 'user', 'content' => [[
                    'type' => 'tool_result',
                    'tool_use_id' => 'toolu_1',
                    'content' => 'hit',
                ]]],
            ],
        ], stream: false);

        $this->assertArrayNotHasKey('thinking', $payload);
        $this->assertSame('gpt-4o', $payload['model']);
        $this->assertSame(64, $payload['max_tokens']);
        $this->assertArrayNotHasKey('max_completion_tokens', $payload);
        $this->assertSame('Be brief.', $payload['messages'][0]['content']);
        $this->assertSame('function', $payload['tools'][0]['type']);
        $this->assertSame('mcp__1__rag_search', $payload['tools'][0]['function']['name']);
        $this->assertSame('tool', $payload['messages'][3]['role']);
        $this->assertSame('hit', $payload['messages'][3]['content']);
    }

    public function testImageBlocksSurviveAsImageUrlParts(): void
    {
        $t = new OpenAiMessagesTranslator(new MockHttpClient());

        $payload = $t->toOpenAiRequest([
            'model' => 'gpt-4o',
            'max_tokens' => 64,
            'messages' => [['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'What is on this page?'],
                ['type' => 'image', 'source' => [
                    'type' => 'base64',
                    'media_type' => 'image/png',
                    'data' => 'iVBORw0KGgo=',
                ]],
                ['type' => 'image', 'source' => ['type' => 'url', 'url' => 'https://example.test/page.png']],
            ]]],
        ], stream: false);

        $content = $payload['messages'][0]['content'];
        $this->assertIsArray($content);
        $this->assertSame('What is on this page?', $content[0]['text']);
        $this->assertSame('data:image/png;base64,iVBORw0KGgo=', $content[1]['image_url']['url']);
        $this->assertSame('https://example.test/page.png', $content[2]['image_url']['url']);
        $this->assertArrayNotHasKey('detail', $content[1]['image_url']);
    }

    public function testConfiguredImageDetailReachesTheUpstream(): void
    {
        $t = new OpenAiMessagesTranslator(new MockHttpClient());

        $payload = $t->toOpenAiRequest([
            'model' => 'gpt-4o',
            'max_tokens' => 64,
            'messages' => [['role' => 'user', 'content' => [
                ['type' => 'image', 'source' => ['type' => 'url', 'url' => 'https://example.test/page.png']],
            ]]],
        ], stream: false, imageDetail: 'low');

        $this->assertSame('low', $payload['messages'][0]['content'][0]['image_url']['detail']);
    }

    public function testAutoImageDetailIsLeftToTheProvider(): void
    {
        $t = new OpenAiMessagesTranslator(new MockHttpClient());

        $payload = $t->toOpenAiRequest([
            'model' => 'gpt-4o',
            'max_tokens' => 64,
            'messages' => [['role' => 'user', 'content' => [
                ['type' => 'image', 'source' => ['type' => 'url', 'url' => 'https://example.test/page.png']],
            ]]],
        ], stream: false, imageDetail: 'auto');

        $this->assertArrayNotHasKey('detail', $payload['messages'][0]['content'][0]['image_url']);
    }

    public function testTextOnlyTurnsStayPlainStrings(): void
    {
        $t = new OpenAiMessagesTranslator(new MockHttpClient());

        $payload = $t->toOpenAiRequest([
            'model' => 'gpt-4o',
            'max_tokens' => 64,
            'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]]],
        ], stream: false);

        $this->assertSame('hi', $payload['messages'][0]['content']);
    }

    public function testReasoningModelsSendMaxCompletionTokens(): void
    {
        $t = new OpenAiMessagesTranslator(new MockHttpClient());

        foreach (['gpt-6-astra', 'openai:gpt-6-astra:chat', 'gpt-5.4', 'o3-mini'] as $model) {
            $payload = $t->toOpenAiRequest([
                'model' => $model,
                'max_tokens' => 1024,
                'messages' => [['role' => 'user', 'content' => 'PONG']],
            ], stream: true);

            $this->assertSame(
                1024,
                $payload['max_completion_tokens'],
                $model.' must remap max_tokens',
            );
            $this->assertArrayNotHasKey('max_tokens', $payload, $model.' must not send max_tokens');
        }
    }

    public function testOpenAiReasoningModelsUseResponses(): void
    {
        $t = new OpenAiMessagesTranslator(new MockHttpClient());

        $this->assertTrue($t->shouldUseResponses(
            ['model' => 'openai:gpt-6-astra:chat'],
            ['provider' => 'openai'],
        ));
        $this->assertTrue($t->shouldUseResponses(
            ['model' => 'gpt-5.4'],
            ['provider' => 'openai'],
        ));
        $this->assertFalse($t->shouldUseResponses(
            ['model' => 'gpt-4o'],
            ['provider' => 'openai'],
        ));
        $this->assertFalse($t->shouldUseResponses(
            ['model' => 'openai:gpt-6-astra:chat'],
            ['provider' => 'groq'],
        ));
        $this->assertFalse($t->shouldUseResponses(
            ['model' => 'gpt-6-astra'],
            ['provider' => 'openai', 'openai_completions_url' => 'https://example.test/v1/chat/completions'],
        ));
        $this->assertSame('https://api.openai.com/v1/responses', $t->resolveResponsesUrl(['provider' => 'openai']));
    }

    public function testToResponsesRequestMapsToolsHistoryAndLowestEffort(): void
    {
        $t = new OpenAiMessagesTranslator(new MockHttpClient());
        $payload = $t->toResponsesRequest([
            'model' => 'openai:gpt-6-astra:chat',
            'max_tokens' => 64,
            'system' => 'Be brief.',
            'tools' => [[
                'name' => 'web_search',
                'description' => 'search',
                'input_schema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]],
            ]],
            'messages' => [
                ['role' => 'user', 'content' => 'hi'],
                ['role' => 'assistant', 'content' => [[
                    'type' => 'tool_use',
                    'id' => 'call_1',
                    'name' => 'web_search',
                    'input' => ['q' => 'x'],
                ]]],
                ['role' => 'user', 'content' => [[
                    'type' => 'tool_result',
                    'tool_use_id' => 'call_1',
                    'content' => 'hit',
                ]]],
            ],
        ], stream: true);

        $this->assertSame('openai:gpt-6-astra:chat', $payload['model']);
        $this->assertSame(64, $payload['max_output_tokens']);
        $this->assertArrayNotHasKey('max_tokens', $payload);
        $this->assertArrayNotHasKey('max_completion_tokens', $payload);
        $this->assertSame('Be brief.', $payload['instructions']);
        $this->assertSame(['effort' => 'low'], $payload['reasoning']);
        $this->assertTrue($payload['stream']);
        $this->assertFalse($payload['store']);
        $this->assertSame('function', $payload['tools'][0]['type']);
        $this->assertSame('web_search', $payload['tools'][0]['name']);
        $this->assertSame('user', $payload['input'][0]['role']);
        $this->assertSame('function_call', $payload['input'][1]['type']);
        $this->assertSame('call_1', $payload['input'][1]['call_id']);
        $this->assertSame('function_call_output', $payload['input'][2]['type']);
        $this->assertSame('hit', $payload['input'][2]['output']);
    }

    /**
     * @param ?string $expectedEffort null means no reasoning block
     */
    #[DataProvider('lowestResponsesEffortProvider')]
    public function testLowestResponsesEffort(string $model, ?string $expectedEffort): void
    {
        $this->assertSame($expectedEffort, OpenAiMessagesTranslator::lowestResponsesEffort($model));
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function lowestResponsesEffortProvider(): array
    {
        return [
            'gpt-6-sol' => ['gpt-6-sol', 'none'],
            'openai:gpt-6-luna' => ['openai:gpt-6-luna', 'none'],
            'gpt-6-astra' => ['gpt-6-astra', 'low'],
            'gpt-5.5-pro' => ['gpt-5.5-pro', 'medium'],
            'gpt-5' => ['gpt-5', 'minimal'],
            'o3' => ['o3', 'low'],
            'gpt-4o' => ['gpt-4o', null],
        ];
    }

    public function testToResponsesRequestSendsNoneForGpt6Sol(): void
    {
        $t = new OpenAiMessagesTranslator(new MockHttpClient());
        $payload = $t->toResponsesRequest([
            'model' => 'gpt-6-sol',
            'max_tokens' => 64,
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ], stream: false);

        $this->assertSame(['effort' => 'none'], $payload['reasoning']);
    }

    public function testToResponsesRequestKeepsNonAutoImageDetail(): void
    {
        $t = new OpenAiMessagesTranslator(new MockHttpClient());
        $payload = $t->toResponsesRequest([
            'model' => 'openai:gpt-6-astra:chat',
            'max_tokens' => 64,
            'messages' => [['role' => 'user', 'content' => [
                ['type' => 'image', 'source' => ['type' => 'url', 'url' => 'https://example.test/page.png']],
            ]]],
        ], stream: false, imageDetail: 'low');

        $part = $payload['input'][0]['content'][0];
        $this->assertSame('input_image', $part['type']);
        $this->assertSame('https://example.test/page.png', $part['image_url']);
        $this->assertSame('low', $part['detail']);
    }

    public function testToResponsesRequestDropsAutoImageDetail(): void
    {
        $t = new OpenAiMessagesTranslator(new MockHttpClient());
        $payload = $t->toResponsesRequest([
            'model' => 'openai:gpt-6-astra:chat',
            'max_tokens' => 64,
            'messages' => [['role' => 'user', 'content' => [
                ['type' => 'image', 'source' => ['type' => 'url', 'url' => 'https://example.test/page.png']],
            ]]],
        ], stream: false, imageDetail: 'auto');

        $part = $payload['input'][0]['content'][0];
        $this->assertSame('input_image', $part['type']);
        $this->assertArrayNotHasKey('detail', $part);
    }

    public function testFromResponsesMapsFunctionCallAndText(): void
    {
        $t = new OpenAiMessagesTranslator(new MockHttpClient());
        $anthropic = $t->fromResponsesResponse([
            'id' => 'resp_1',
            'status' => 'completed',
            'output' => [
                [
                    'type' => 'function_call',
                    'call_id' => 'call_1',
                    'name' => 'web_search',
                    'arguments' => '{"q":"node lts"}',
                ],
                [
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => 'Node 24']],
                ],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], ['model' => 'gpt-6-astra']);

        $this->assertSame('tool_use', $anthropic['stop_reason']);
        $this->assertSame('tool_use', $anthropic['content'][0]['type']);
        $this->assertSame(['q' => 'node lts'], $anthropic['content'][0]['input']);
        $this->assertSame('text', $anthropic['content'][1]['type']);
        $this->assertSame('Node 24', $anthropic['content'][1]['text']);
        $this->assertSame(10, $anthropic['usage']['input_tokens']);
    }

    public function testCompletePostsGpt6ToResponses(): void
    {
        $seenUrl = null;
        $seenBody = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seenUrl, &$seenBody): MockResponse {
            $seenUrl = $url;
            $seenBody = json_decode((string) ($options['body'] ?? ''), true);

            return new MockResponse((string) json_encode([
                'id' => 'resp_1',
                'status' => 'completed',
                'output' => [[
                    'type' => 'message',
                    'content' => [['type' => 'output_text', 'text' => 'PONG']],
                ]],
                'usage' => ['input_tokens' => 3, 'output_tokens' => 1],
            ]));
        });
        $t = new OpenAiMessagesTranslator($client);

        $result = $t->complete(
            [
                'model' => 'gpt-6-astra',
                'max_tokens' => 64,
                'messages' => [['role' => 'user', 'content' => 'Reply with PONG']],
            ],
            [
                'api_key' => 'sk_test',
                'upstream_url' => 'https://api.anthropic.com',
                'provider' => 'openai',
            ],
        );

        $this->assertSame('https://api.openai.com/v1/responses', $seenUrl);
        $this->assertIsArray($seenBody);
        $this->assertSame(64, $seenBody['max_output_tokens']);
        $this->assertSame(['effort' => 'low'], $seenBody['reasoning']);
        $this->assertSame(200, $result['status']);
        $this->assertIsArray($result['body']);
        $this->assertSame('PONG', $result['body']['content'][0]['text']);
    }

    public function testStreamMapsResponsesTextDelta(): void
    {
        $sse = "event: response.output_text.delta\n"
            ."data: {\"type\":\"response.output_text.delta\",\"delta\":\"PONG\"}\n\n"
            ."event: response.completed\n"
            ."data: {\"type\":\"response.completed\",\"response\":{\"id\":\"resp_1\",\"status\":\"completed\",\"usage\":{\"input_tokens\":3,\"output_tokens\":1}}}\n\n";
        $client = new MockHttpClient(new MockResponse($sse));
        $t = new OpenAiMessagesTranslator($client);
        $events = [];
        $usage = $t->stream(
            [
                'model' => 'gpt-6-astra',
                'max_tokens' => 64,
                'messages' => [['role' => 'user', 'content' => 'PONG']],
            ],
            ['api_key' => 'sk_test', 'upstream_url' => 'https://api.anthropic.com', 'provider' => 'openai'],
            static function (array $event) use (&$events): void {
                $events[] = $event;
            },
        );

        $deltas = array_values(array_filter(
            $events,
            static fn (array $e): bool => 'content_block_delta' === $e['event'],
        ));
        $this->assertSame('PONG', $deltas[0]['data']['delta']['text'] ?? null);
        $this->assertSame('end_turn', $usage->stopReason);
        $this->assertSame(3, $usage->inputTokens);
    }

    public function testServerToolDeclarationsAreNotMappedToFunctions(): void
    {
        $t = new OpenAiMessagesTranslator(new MockHttpClient());

        $payload = $t->toOpenAiRequest([
            'model' => 'gpt-4o',
            'max_tokens' => 64,
            'tools' => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5]],
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ], stream: false);

        $this->assertArrayNotHasKey('tools', $payload);
    }

    public function testFromOpenAiMapsToolCalls(): void
    {
        $t = new OpenAiMessagesTranslator(new MockHttpClient());
        $anthropic = $t->fromOpenAiResponse([
            'id' => 'chatcmpl_1',
            'model' => 'gpt-4o',
            'choices' => [[
                'finish_reason' => 'tool_calls',
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => [
                            'name' => 'mcp__1__rag_search',
                            'arguments' => '{"q":"test"}',
                        ],
                    ]],
                ],
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ], ['model' => 'gpt-4o']);

        $this->assertSame('tool_use', $anthropic['stop_reason']);
        $this->assertSame('tool_use', $anthropic['content'][0]['type']);
        $this->assertSame(['q' => 'test'], $anthropic['content'][0]['input']);
        $this->assertSame(10, $anthropic['usage']['input_tokens']);
    }

    public function testSupportsEveryOpenAiCompatibleChatProvider(): void
    {
        $t = new OpenAiMessagesTranslator(new MockHttpClient());

        $this->assertTrue($t->supports('openai'));
        $this->assertTrue($t->supports('groq'));
        $this->assertTrue($t->supports('mistral'));
        $this->assertTrue($t->supports('xai'));
        $this->assertTrue($t->supports('huggingface'));
        $this->assertTrue($t->supports('trustedtokens'));
        $this->assertTrue($t->supports('a2agent'));
        $this->assertTrue($t->supports('meta'));
        $this->assertTrue($t->supports('perplexity'));
        $this->assertTrue($t->supports('ollama'));
        $this->assertTrue($t->supports(OpenAiCompatibleEndpointRegistry::PROVIDER_NAME));
        $this->assertFalse($t->supports('anthropic'));
        $this->assertFalse($t->supports('google'));
        $this->assertFalse($t->supports('gemini'));
        $this->assertFalse($t->supports('triton'));
    }

    public function testResolvesFixedCloudCompletionsUrls(): void
    {
        $t = new OpenAiMessagesTranslator(new MockHttpClient());

        $this->assertSame(
            ChatCompletionsUpstreams::URLS['groq'],
            $t->resolveCompletionsUrl(['provider' => 'groq']),
        );
        $this->assertSame(
            ChatCompletionsUpstreams::URLS['mistral'],
            $t->resolveCompletionsUrl(['provider' => 'mistral']),
        );
        $this->assertSame(
            ChatCompletionsUpstreams::URLS['openai'],
            $t->resolveCompletionsUrl(['provider' => 'openai']),
        );
        $this->assertSame(
            ChatCompletionsUpstreams::URLS['a2agent'],
            $t->resolveCompletionsUrl(['provider' => 'a2agent']),
        );
        $this->assertSame(
            'https://api.meta.ai/v1/chat/completions',
            $t->resolveCompletionsUrl(['provider' => 'meta']),
        );
        $this->assertSame(
            'https://api.openai.com/v1/chat/completions',
            $t->resolveCompletionsUrl(['openai_upstream_url' => 'https://api.openai.com']),
        );
    }

    public function testResolvesOllamaFromConfiguredBaseUrl(): void
    {
        $t = new OpenAiMessagesTranslator(new MockHttpClient(), 'http://ollama:11434');

        $this->assertSame(
            'http://ollama:11434/v1/chat/completions',
            $t->resolveCompletionsUrl(['provider' => 'ollama']),
        );
    }

    public function testUnconfiguredOllamaHasNoUpstream(): void
    {
        $t = new OpenAiMessagesTranslator(new MockHttpClient());

        $this->assertNull($t->resolveCompletionsUrl(['provider' => 'ollama']));
    }

    public function testCompletePostsGroqTurnsToGroq(): void
    {
        $seenUrl = null;
        $client = new MockHttpClient(static function (string $method, string $url) use (&$seenUrl): MockResponse {
            $seenUrl = $url;

            return new MockResponse((string) json_encode([
                'id' => 'chatcmpl_1',
                'model' => 'llama-3.3-70b-versatile',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['role' => 'assistant', 'content' => 'hi'],
                ]],
                'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 1],
            ]));
        });
        $t = new OpenAiMessagesTranslator($client);

        $result = $t->complete(
            [
                'model' => 'llama-3.3-70b-versatile',
                'max_tokens' => 64,
                'messages' => [['role' => 'user', 'content' => 'hi']],
            ],
            [
                'api_key' => 'gsk_test',
                'upstream_url' => 'https://api.anthropic.com',
                'provider' => 'groq',
            ],
        );

        $this->assertSame(ChatCompletionsUpstreams::URLS['groq'], $seenUrl);
        $this->assertSame(200, $result['status']);
        $this->assertIsArray($result['body']);
        $this->assertSame('hi', $result['body']['content'][0]['text']);
    }
}
