<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider\Google;

use App\AI\Provider\Google\GoogleMessageFormat;
use PHPUnit\Framework\TestCase;

final class GoogleMessageFormatTest extends TestCase
{
    public function testAConversationBecomesAlternatingTurnsThatStartWithTheUser(): void
    {
        $contents = GoogleMessageFormat::contents([
            ['role' => 'assistant', 'content' => 'Welcome back.'],
            ['role' => 'system', 'content' => 'Be brief.'],
            ['role' => 'user', 'content' => 'Weather?'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'call_1', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Berlin"}']]]],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => ['temp' => 18]],
            ['role' => 'assistant', 'content' => '   '],
            ['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAAA']], ['type' => 'text', 'text' => ' ']]],
        ]);

        self::assertSame([
            ['role' => 'user', 'parts' => [['text' => 'Be brief.'], ['text' => 'Weather?']]],
            ['role' => 'model', 'parts' => [['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Berlin']]]]],
            ['role' => 'user', 'parts' => [
                ['functionResponse' => ['name' => 'get_weather', 'response' => ['content' => '{"temp":18}']]],
                ['inline_data' => ['mime_type' => 'image/png', 'data' => 'AAAA']],
            ]],
        ], $contents);
    }

    public function testToolsBecomeFunctionDeclarationsAndAToolConfig(): void
    {
        $tools = [['type' => 'function', 'function' => ['name' => 'get_weather', 'parameters' => ['type' => 'object']]]];

        $payload = GoogleMessageFormat::withTools(['contents' => []], ['tools' => $tools, 'tool_choice' => 'auto']);

        self::assertSame('get_weather', $payload['tools'][0]['functionDeclarations'][0]['name']);
        self::assertSame(['functionCallingConfig' => ['mode' => 'AUTO']], $payload['toolConfig']);
        self::assertSame(['contents' => []], GoogleMessageFormat::withTools(['contents' => []], ['tools' => []]));
    }

    public function testAGeminiFunctionCallBecomesAnOpenAiToolCall(): void
    {
        $call = GoogleMessageFormat::toolCall(['name' => 'get_weather', 'args' => ['city' => 'Paris']]);

        self::assertMatchesRegularExpression('/^call_[0-9a-f]{12}$/', $call['id']);
        self::assertSame(['type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Paris"}']], ['type' => $call['type'], 'function' => $call['function']]);
        self::assertSame('{}', GoogleMessageFormat::toolCall(['name' => 'noop', 'args' => ''])['function']['arguments']);
    }
}
