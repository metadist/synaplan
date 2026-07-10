<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Repository\SearchResultRepository;
use App\Service\File\DataUrlFixer;
use App\Service\Message\MessageApiFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Covers the taximeter `usage` serialization added to the history/message API
 * (plan 1c): tokens from ai_chat_usage, charged cost from ai_chat_cost, model
 * key from ai_chat_provider/model. Absent meta ⇒ field omitted (null), not a
 * null-filled object.
 */
final class MessageApiFormatterUsageTest extends TestCase
{
    private MessageApiFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new MessageApiFormatter(
            $this->createMock(MessageRepository::class),
            $this->createMock(SearchResultRepository::class),
            $this->createMock(DataUrlFixer::class),
        );
    }

    private function makeOutMessage(): Message
    {
        return $this->makeMessage('OUT', 'Hello world');
    }

    /**
     * MessageMeta::setMessage() copies the (int) message id, so a transient
     * Message needs an id before setMeta() works. Assign one via reflection.
     */
    private function makeMessage(string $direction, string $text): Message
    {
        $m = new Message();
        $m->setDirection($direction);
        $m->setText($text);
        $m->setUnixTimestamp(1_720_000_000);

        (new \ReflectionProperty(Message::class, 'id'))->setValue($m, 4242);

        return $m;
    }

    public function testUsageSerializedWithTokensCostAndModelKey(): void
    {
        $m = $this->makeOutMessage();
        $m->setMeta('ai_chat_usage', json_encode([
            'prompt_tokens' => 1234,
            'completion_tokens' => 987,
            'total_tokens' => 2221,
        ]));
        $m->setMeta('ai_chat_cost', '0.031200');
        $m->setMeta('ai_chat_provider', 'OpenAI');
        $m->setMeta('ai_chat_model', 'gpt-4o');

        $usage = $this->formatter->format($m)['usage'];

        self::assertIsArray($usage);
        self::assertSame(1234, $usage['promptTokens']);
        self::assertSame(987, $usage['completionTokens']);
        self::assertSame(2221, $usage['totalTokens']);
        self::assertSame('0.031200', $usage['cost']);
        self::assertSame('openai:gpt-4o', $usage['modelKey']);
        self::assertSame('LLM', $usage['kind']);
    }

    public function testUsageOmittedWhenNoMetaPresent(): void
    {
        $result = $this->formatter->format($this->makeOutMessage());

        self::assertArrayHasKey('usage', $result);
        self::assertNull($result['usage']);
    }

    public function testCostNullWhenChargedCostMetaAbsent(): void
    {
        $m = $this->makeOutMessage();
        $m->setMeta('ai_chat_usage', json_encode([
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'total_tokens' => 15,
        ]));
        // No ai_chat_cost meta (e.g. a non-web channel that skipped the taximeter write).

        $usage = $this->formatter->format($m)['usage'];

        self::assertIsArray($usage);
        self::assertNull($usage['cost']);
        self::assertSame(15, $usage['totalTokens']);
    }

    public function testTotalTokensDerivedFromPromptPlusCompletionWhenMissing(): void
    {
        $m = $this->makeOutMessage();
        $m->setMeta('ai_chat_usage', json_encode([
            'prompt_tokens' => 40,
            'completion_tokens' => 2,
        ]));

        $usage = $this->formatter->format($m)['usage'];

        self::assertIsArray($usage);
        self::assertSame(42, $usage['totalTokens']);
    }

    public function testIncomingMessageHasNoUsage(): void
    {
        $m = $this->makeMessage('IN', 'question');
        $m->setMeta('ai_chat_usage', json_encode(['total_tokens' => 99]));

        self::assertNull($this->formatter->format($m)['usage']);
    }
}
