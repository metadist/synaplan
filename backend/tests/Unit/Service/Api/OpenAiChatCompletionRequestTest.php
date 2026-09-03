<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Api;

use App\Service\Api\OpenAiChatCompletionRequest;
use App\Service\Api\OpenAiChatCompletionRequestException;
use PHPUnit\Framework\TestCase;

final class OpenAiChatCompletionRequestTest extends TestCase
{
    public function testParsesMessagesAndLeavesToolTurnsIntact(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'weather?'],
            [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Berlin"}'],
                ]],
            ],
            ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"ok":true}'],
        ];

        $parsed = OpenAiChatCompletionRequest::fromBody([
            'messages' => $messages,
            'model' => 'test-model',
            'tools' => [[
                'type' => 'function',
                'function' => ['name' => 'get_weather', 'parameters' => ['type' => 'object']],
            ]],
            'tool_choice' => 'auto',
            'parallel_tool_calls' => true,
            'stream' => true,
            'stream_options' => ['include_usage' => true],
        ]);

        self::assertSame($messages, $parsed->messages);
        self::assertSame('test-model', $parsed->model);
        self::assertTrue($parsed->stream);
        self::assertTrue($parsed->includeUsage);
        self::assertTrue($parsed->parallelToolCalls);
        self::assertTrue($parsed->requestsTools());
        self::assertSame('auto', $parsed->toolChoice);
        self::assertArrayHasKey('tools', $parsed->providerToolOptions());
    }

    public function testMissingMessages(): void
    {
        $this->expectException(OpenAiChatCompletionRequestException::class);
        $this->expectExceptionMessage('messages is required');

        try {
            OpenAiChatCompletionRequest::fromBody(['model' => 'gpt-4o']);
        } catch (OpenAiChatCompletionRequestException $e) {
            self::assertSame('missing_messages', $e->errorCode);
            throw $e;
        }
    }

    public function testMalformedTools(): void
    {
        $this->expectException(OpenAiChatCompletionRequestException::class);

        try {
            OpenAiChatCompletionRequest::fromBody([
                'messages' => [['role' => 'user', 'content' => 'hi']],
                'tools' => [['type' => 'not-a-function']],
            ]);
        } catch (OpenAiChatCompletionRequestException $e) {
            self::assertSame('invalid_tools', $e->errorCode);
            throw $e;
        }
    }

    public function testMalformedToolChoice(): void
    {
        $this->expectException(OpenAiChatCompletionRequestException::class);

        try {
            OpenAiChatCompletionRequest::fromBody([
                'messages' => [['role' => 'user', 'content' => 'hi']],
                'tool_choice' => 'maybe',
            ]);
        } catch (OpenAiChatCompletionRequestException $e) {
            self::assertSame('invalid_tool_choice', $e->errorCode);
            throw $e;
        }
    }

    public function testEmptyToolsDoesNotRequestTools(): void
    {
        $parsed = OpenAiChatCompletionRequest::fromBody([
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'tools' => [],
        ]);

        self::assertFalse($parsed->requestsTools());
        self::assertSame([], $parsed->providerToolOptions());
    }

    public function testToolChoiceRequiredWithoutToolsStillRequestsTools(): void
    {
        $parsed = OpenAiChatCompletionRequest::fromBody([
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'tool_choice' => 'required',
        ]);

        self::assertTrue($parsed->requestsTools());
    }

    public function testToolChoiceNoneDoesNotRequestToolsOnItsOwn(): void
    {
        $parsed = OpenAiChatCompletionRequest::fromBody([
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'tool_choice' => 'none',
        ]);

        self::assertFalse($parsed->requestsTools());
    }

    public function testToolsPresentWithChoiceNoneStillRequestsTheGate(): void
    {
        $parsed = OpenAiChatCompletionRequest::fromBody([
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'tools' => [[
                'type' => 'function',
                'function' => ['name' => 'noop'],
            ]],
            'tool_choice' => 'none',
        ]);

        self::assertTrue($parsed->requestsTools());
    }
}
