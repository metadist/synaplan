<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider\Google;

use App\AI\Provider\Google\GoogleChat;
use App\AI\StructuredOutput\StructuredOutputCapability;
use App\AI\StructuredOutput\StructuredOutputTranslator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GoogleChatTest extends TestCase
{
    use GoogleApiDouble;

    public function testABufferedAnswerKeepsTextAndToolCallsApartFromThoughts(): void
    {
        $chat = $this->chat([self::json([
            'candidates' => [['content' => ['parts' => [['text' => 'Hidden.', 'thought' => true], ['text' => 'Visible.'], ['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Rome']]]]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 2],
        ])]);

        $result = $chat->chat([['role' => 'user', 'content' => 'Weather in Rome?']], ['model' => 'gemini-2.5-flash', 'max_tokens' => 128]);

        self::assertSame(['Visible.', 'get_weather', '{"city":"Rome"}', 'tool_calls', 5], [$result['content'], $result['tool_calls'][0]['function']['name'], $result['tool_calls'][0]['function']['arguments'], $result['finish_reason'], $result['usage']['total_tokens']]);
        $request = $this->requests()[0];
        self::assertSame('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent', $request['url']);
        self::assertSame(128, $request['body']['generationConfig']['maxOutputTokens']);
    }

    public function testAStreamSendsReasoningTextAndTheFinishInOrder(): void
    {
        $chunk = static fn (array $data): string => 'data: '.json_encode($data)."\r\n\r\n";
        $chat = $this->chat([new MockResponse([
            $chunk(['candidates' => [['content' => ['parts' => [['text' => 'Pondering.', 'thought' => true]]]]]]),
            $chunk(['candidates' => [['content' => ['parts' => [['text' => 'Hello.']]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 4, 'candidatesTokenCount' => 1]]),
        ])]);
        $events = [];

        $result = $chat->chatStream([['role' => 'user', 'content' => 'Hi']], static function (mixed $event) use (&$events): void {
            $events[] = $event;
        }, ['model' => 'gemini-2.5-flash', 'reasoning_effort' => 'low']);

        self::assertSame([['type' => 'reasoning', 'content' => 'Pondering.'], 'Hello.', ['type' => 'finish', 'finish_reason' => 'stop']], $events);
        self::assertSame(5, $result['usage']['total_tokens']);
        self::assertSame(['thinkingBudget' => 512], $this->requests()[0]['body']['generationConfig']['thinkingConfig']);
    }

    public function testArgonDropsSamplingAndBillsThinkingTokens(): void
    {
        $chat = $this->chat([self::json([
            'candidates' => [['content' => ['parts' => [['text' => 'Visible.']]], 'finishReason' => 'STOP']],
            'usageMetadata' => [
                'promptTokenCount' => 10,
                'candidatesTokenCount' => 4,
                'thoughtsTokenCount' => 6,
                'cachedContentTokenCount' => 3,
            ],
        ])]);

        $result = $chat->chat([['role' => 'user', 'content' => 'Hi']], [
            'model' => 'gemini-4-argon',
            'temperature' => 0.1,
            'thinking_budget' => 2048,
        ]);

        $request = $this->requests()[0];
        $config = $request['body']['generationConfig'];
        self::assertArrayNotHasKey('thinkingConfig', $config);
        self::assertArrayNotHasKey('temperature', $config);
        self::assertArrayNotHasKey('topP', $config);
        self::assertArrayNotHasKey('topK', $config);
        self::assertEqualsWithDelta(900, (float) $request['timeout'], 0.001);
        self::assertSame([10, 3, 20], [$result['usage']['completion_tokens'], $result['usage']['cached_tokens'], $result['usage']['total_tokens']]);
    }

    public function testArgonMapsEffortOffToTheLowestThinkingLevel(): void
    {
        $chunk = static fn (array $data): string => 'data: '.json_encode($data)."\r\n\r\n";
        $chat = $this->chat([new MockResponse([
            $chunk([
                'candidates' => [['content' => ['parts' => [['text' => 'Done.']]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 1000, 'candidatesTokenCount' => 20, 'thoughtsTokenCount' => 80, 'cachedContentTokenCount' => 200],
            ]),
        ])]);

        $result = $chat->chatStream([['role' => 'user', 'content' => 'Hi']], static function (mixed $event): void {
        }, ['model' => 'gemini-4-argon', 'reasoning_effort' => 'off', 'temperature' => 0.2, 'thinking_budget' => 0]);

        $config = $this->requests()[0]['body']['generationConfig'];
        self::assertSame(['thinkingLevel' => 'low'], $config['thinkingConfig']);
        self::assertArrayNotHasKey('temperature', $config);
        self::assertEqualsWithDelta(900, (float) $this->requests()[0]['timeout'], 0.001);
        self::assertSame([100, 200, 1100], [$result['usage']['completion_tokens'], $result['usage']['cached_tokens'], $result['usage']['total_tokens']]);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function chat(array $responses): GoogleChat
    {
        return new GoogleChat($this->api($responses), new NullLogger(), new StructuredOutputTranslator(new StructuredOutputCapability()));
    }
}
