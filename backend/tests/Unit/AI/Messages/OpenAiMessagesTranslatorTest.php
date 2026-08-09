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
