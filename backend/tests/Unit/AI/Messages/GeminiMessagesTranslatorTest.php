<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\Translator\GeminiMessagesTranslator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class GeminiMessagesTranslatorTest extends TestCase
{
    public function testStripsThinkingAndUsesParametersJsonSchema(): void
    {
        $t = new GeminiMessagesTranslator(new MockHttpClient());
        $payload = $t->toGeminiRequest([
            'model' => 'gemini-2.0-flash',
            'max_tokens' => 32,
            'thinking' => ['type' => 'adaptive'],
            'system' => 'Sys',
            'tools' => [[
                'name' => 'mcp__2__memory_search',
                'description' => 'mem',
                'input_schema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
            ]],
            'messages' => [['role' => 'user', 'content' => 'hello']],
        ]);

        $this->assertArrayNotHasKey('thinking', $payload);
        $this->assertSame('Sys', $payload['systemInstruction']['parts'][0]['text']);
        $this->assertSame(
            'mcp__2__memory_search',
            $payload['tools'][0]['functionDeclarations'][0]['name'],
        );
        $this->assertArrayHasKey('parametersJsonSchema', $payload['tools'][0]['functionDeclarations'][0]);
        $this->assertSame('user', $payload['contents'][0]['role']);
    }

    public function testFromGeminiMapsFunctionCall(): void
    {
        $t = new GeminiMessagesTranslator(new MockHttpClient());
        $anthropic = $t->fromGeminiResponse([
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => [
                    'parts' => [[
                        'functionCall' => [
                            'name' => 'mcp__2__memory_search',
                            'args' => ['query' => 'x'],
                        ],
                    ]],
                ],
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 7,
                'candidatesTokenCount' => 3,
            ],
        ], ['model' => 'gemini-2.0-flash']);

        $this->assertSame('tool_use', $anthropic['stop_reason']);
        $this->assertSame('mcp__2__memory_search', $anthropic['content'][0]['name']);
        $this->assertSame(7, $anthropic['usage']['input_tokens']);
    }
}
