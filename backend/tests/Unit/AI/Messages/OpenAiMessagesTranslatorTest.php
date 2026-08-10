<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\Translator\OpenAiMessagesTranslator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

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
}
