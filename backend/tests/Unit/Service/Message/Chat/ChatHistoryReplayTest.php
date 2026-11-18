<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Chat;

use App\Entity\Message;
use App\Repository\ModelRepository;
use App\Service\File\ConversationFileCatalog;
use App\Service\File\GeneratedImageVisionFlag;
use App\Service\Message\Chat\ChatMessageAssembler;
use App\Service\Message\Chat\ConversationHistoryRenderer;
use App\Service\Vision\VisionAttachmentEncoder;
use App\Service\Vision\VisionModelResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * What the model reads of earlier turns: an answer without the reasoning
 * block the chat stores for its reasoning panel, and nothing of a turn that
 * failed. A replayed `<think>` block made Claude refuse every follow-up; a
 * replayed error reply put words the model never said into its mouth.
 */
final class ChatHistoryReplayTest extends TestCase
{
    private const ANSWER = 'Nein, deinen Namen kenne ich nicht.';

    public function testAnAnswerIsReplayedWithoutItsReasoningBlock(): void
    {
        $thread = [
            $this->turn(1, 'IN', 'weisst Du, wer ich bin?'),
            $this->turn(2, 'OUT', "<think>I'm checking whether I know who this person is.</think>\n\n".self::ANSWER),
        ];

        $messages = $this->assembler()->streaming('system', $thread, $this->current(), []);

        self::assertSame(['system', 'user', 'assistant', 'user'], array_column($messages, 'role'));
        self::assertSame(self::ANSWER, $messages[2]['content']);
        self::assertStringNotContainsString('<think>', (string) json_encode($messages));
    }

    public function testAnAnswerThatIsOnlyReasoningIsLeftOut(): void
    {
        $thread = [
            $this->turn(1, 'IN', 'weisst Du, wer ich bin?'),
            $this->turn(2, 'OUT', '<think>still checking'),
        ];

        $messages = $this->assembler()->streaming(null, $thread, $this->current(), []);

        self::assertSame(['user', 'user'], array_column($messages, 'role'));
    }

    public function testAQuestionKeepsItsOwnWords(): void
    {
        $thread = [$this->turn(1, 'IN', 'Was macht das Tag <think> in einem Prompt?')];

        $messages = $this->assembler()->streaming(null, $thread, $this->current(), []);

        self::assertSame('Was macht das Tag <think> in einem Prompt?', $messages[0]['content']);
    }

    public function testAFailedTurnIsLeftOut(): void
    {
        $messages = $this->assembler()->streaming(null, $this->threadWithAFailedTurn(), $this->current(), []);

        self::assertSame(['user', 'assistant', 'user'], array_column($messages, 'role'));
        self::assertSame(self::ANSWER, $messages[1]['content']);
        self::assertStringNotContainsString('schiefgelaufen', (string) json_encode($messages));
    }

    public function testTheBufferedChannelsReadTheSameHistory(): void
    {
        $thread = $this->threadWithAFailedTurn();
        $thread[1] = $this->turn(2, 'OUT', '<think>checking</think>'.self::ANSWER);

        $messages = $this->assembler()->buffered('system', $thread, $this->current(), []);

        self::assertSame(['system', 'user', 'assistant', 'user'], array_column($messages, 'role'));
        self::assertSame(self::ANSWER, $messages[2]['content']);
    }

    public function testAFailedTurnIsNotCountedAsReplayed(): void
    {
        $current = $this->current();

        $ids = $this->history()->verbatimMessageIds($this->threadWithAFailedTurn(), $current, true);

        self::assertSame([1, 2, 9], $ids);
    }

    /**
     * @return list<Message>
     */
    private function threadWithAFailedTurn(): array
    {
        return [
            $this->turn(1, 'IN', 'weisst Du, wer ich bin?'),
            $this->turn(2, 'OUT', self::ANSWER),
            $this->turn(3, 'IN', 'du kennst meinen namen', 'ERROR', 'error'),
            $this->turn(4, 'OUT', 'Bei der Beantwortung dieser Anfrage ist etwas schiefgelaufen. Bitte versuche es erneut.', 'ERROR'),
        ];
    }

    private function current(): Message
    {
        return $this->turn(9, 'IN', 'du kennst meinen namen');
    }

    private function turn(int $id, string $direction, string $text, string $topic = 'general', string $status = 'complete'): Message
    {
        $message = (new Message())
            ->setDirection($direction)
            ->setText($text)
            ->setTopic($topic)
            ->setStatus($status)
            ->setUnixTimestamp(1_794_855_000 + $id)
            ->setDateTime(date('YmdHis', 1_794_855_000 + $id));
        (new \ReflectionProperty(Message::class, 'id'))->setValue($message, $id);

        return $message;
    }

    private function history(): ConversationHistoryRenderer
    {
        return new ConversationHistoryRenderer($this->createStub(ConversationFileCatalog::class));
    }

    private function assembler(): ChatMessageAssembler
    {
        $catalog = $this->createStub(ConversationFileCatalog::class);

        return new ChatMessageAssembler(
            new VisionAttachmentEncoder('/tmp/uploads', $catalog, $this->createStub(GeneratedImageVisionFlag::class), $this->createStub(ModelRepository::class), $this->createStub(VisionModelResolver::class), new NullLogger()),
            new ConversationHistoryRenderer($catalog),
            new NullLogger(),
        );
    }
}
