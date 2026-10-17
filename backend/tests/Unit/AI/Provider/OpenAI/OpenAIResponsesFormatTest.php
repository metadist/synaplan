<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider\OpenAI;

use App\AI\Provider\OpenAI\OpenAIResponsesFormat;
use PHPUnit\Framework\TestCase;

final class OpenAIResponsesFormatTest extends TestCase
{
    public function testTheConversationBecomesInstructionsAndInputItems(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'Be brief.'],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Weather?'], ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAAA']]]],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'call_1', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Rome"}']]]],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => ['temp' => 21]],
        ];

        self::assertSame('Be brief.', OpenAIResponsesFormat::extractSystemMessage($messages));
        self::assertSame([
            ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Weather?'], ['type' => 'input_image', 'image_url' => 'data:image/png;base64,AAAA']]],
            ['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'get_weather', 'arguments' => '{"city":"Rome"}'],
            ['type' => 'function_call_output', 'call_id' => 'call_1', 'output' => '{"temp":21}'],
        ], OpenAIResponsesFormat::convertToResponsesFormat(OpenAIResponsesFormat::removeSystemMessages($messages)));
    }

    public function testToolCallsAndUsageComeBackInTheInternalShape(): void
    {
        $answer = [
            'output' => [['type' => 'message'], ['type' => 'function_call', 'call_id' => 'call_9', 'name' => 'get_weather', 'arguments' => '']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 4, 'total_tokens' => 14, 'input_tokens_details' => ['cached_tokens' => 2, 'cache_write_tokens' => 3]],
        ];

        self::assertSame([['id' => 'call_9', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{}']]], OpenAIResponsesFormat::extractResponsesToolCalls($answer));
        self::assertTrue(OpenAIResponsesFormat::responsesOutputHasFunctionCall($answer['output']));
        self::assertSame(['prompt_tokens' => 10, 'completion_tokens' => 4, 'total_tokens' => 14, 'cached_tokens' => 2, 'cache_creation_tokens' => 3], OpenAIResponsesFormat::normalizeResponsesUsage($answer));
    }

    public function testOnlyTheLastUserTurnGoesWithAPreviousResponse(): void
    {
        $input = [['role' => 'user', 'content' => 'First'], ['role' => 'assistant', 'content' => 'Reply'], ['role' => 'user', 'content' => 'Second']];

        self::assertSame([['role' => 'user', 'content' => 'Second']], OpenAIResponsesFormat::reduceToLastUserMessage($input));
    }
}
