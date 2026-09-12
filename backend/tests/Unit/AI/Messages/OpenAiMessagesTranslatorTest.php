<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Credential\OpenAiCompatibleEndpointRegistry;
use App\AI\Messages\Translator\ChatCompletionsUpstreams;
use App\AI\Messages\Translator\OpenAiMessagesTranslator;
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
