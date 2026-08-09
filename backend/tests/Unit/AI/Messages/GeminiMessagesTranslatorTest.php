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

    public function testImageBlocksSurviveAsInlineAndFileData(): void
    {
        $t = new GeminiMessagesTranslator(new MockHttpClient());
        $payload = $t->toGeminiRequest([
            'model' => 'gemini-2.0-flash',
            'max_tokens' => 32,
            'messages' => [['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'What is on this page?'],
                ['type' => 'image', 'source' => [
                    'type' => 'base64',
                    'media_type' => 'image/png',
                    'data' => 'iVBORw0KGgo=',
                ]],
                ['type' => 'image', 'source' => [
                    'type' => 'url',
                    'media_type' => 'image/jpeg',
                    'url' => 'https://example.test/page.jpg',
                ]],
            ]]],
        ]);

        $parts = $payload['contents'][0]['parts'];
        $this->assertSame('What is on this page?', $parts[0]['text']);
        $this->assertSame('image/png', $parts[1]['inlineData']['mimeType']);
        $this->assertSame('iVBORw0KGgo=', $parts[1]['inlineData']['data']);
        $this->assertSame('https://example.test/page.jpg', $parts[2]['fileData']['fileUri']);
    }

    public function testServerToolDeclarationsAreNotMappedToFunctionDeclarations(): void
    {
        $t = new GeminiMessagesTranslator(new MockHttpClient());
        $payload = $t->toGeminiRequest([
            'model' => 'gemini-2.0-flash',
            'max_tokens' => 32,
            'tools' => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5]],
            'messages' => [['role' => 'user', 'content' => 'hello']],
        ]);

        $this->assertArrayNotHasKey('tools', $payload);
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
