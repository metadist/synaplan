<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider\OpenAI;

use App\AI\Provider\OpenAI\OpenAIApiClient;
use App\AI\Provider\OpenAI\OpenAIChat;
use App\AI\Provider\OpenAI\OpenAIResponses;
use App\AI\StructuredOutput\StructuredOutputCapability;
use App\AI\StructuredOutput\StructuredOutputTranslator;
use App\Tests\Characterization\Support\OpenAIFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OpenAIChatTest extends TestCase
{
    use OpenAIApiDouble;

    public function testABufferedAnswerKeepsItsTextAndToolCalls(): void
    {
        $chat = $this->chat($this->api([
            OpenAIFixtures::model('gpt-4o'),
            OpenAIFixtures::response([OpenAIFixtures::message('Checking.'), OpenAIFixtures::functionCall('get_weather', '{"city":"Rome"}', 'call_7')]),
        ]));

        $result = $chat->chat([['role' => 'system', 'content' => 'Be brief.'], ['role' => 'user', 'content' => 'Weather in Rome?']], ['model' => 'gpt-4o']);

        self::assertSame(['Checking.', 'call_7', 'tool_calls', 30], [$result['content'], $result['tool_calls'][0]['id'], $result['finish_reason'], $result['usage']['total_tokens']]);
        $body = $this->sdk->requests()[1]['body'];
        self::assertSame(['Be brief.', 0.7, false], [$body['instructions'], $body['temperature'], $body['store']]);
    }

    public function testTheStreamSendsReasoningTextAndTheFinishInOrder(): void
    {
        $delta = static fn (string $type, string $text): array => ['type' => $type, 'item_id' => 'i_1', 'output_index' => 0, 'content_index' => 0, 'summary_index' => 0, 'delta' => $text];
        $chat = $this->chat($this->api([
            OpenAIFixtures::model('gpt-5.5'),
            OpenAIFixtures::sse(
                $delta('response.reasoning_summary_text.delta', 'Pondering.'),
                $delta('response.output_text.delta', 'Hello.'),
                ['type' => 'response.completed', 'response' => OpenAIFixtures::responseArray([], ['status' => 'completed'])],
            ),
        ]));
        $events = [];

        $result = $chat->chatStream([['role' => 'user', 'content' => 'Hi']], static function (array $event) use (&$events): void {
            $events[] = $event;
        }, ['model' => 'gpt-5.5', 'reasoning_effort' => 'high']);

        self::assertSame([['type' => 'reasoning', 'content' => 'Pondering.'], ['type' => 'content', 'content' => 'Hello.'], ['type' => 'finish', 'finish_reason' => 'stop']], $events);
        self::assertSame(30, $result['usage']['total_tokens']);
        self::assertSame(['effort' => 'high', 'summary' => 'auto'], $this->sdk->requests()[1]['body']['reasoning']);
    }

    private function chat(OpenAIApiClient $api): OpenAIChat
    {
        return new OpenAIChat($api, new OpenAIResponses($api, new NullLogger()), new NullLogger(), new StructuredOutputTranslator(new StructuredOutputCapability()), false);
    }
}
