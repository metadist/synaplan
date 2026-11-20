<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\Entity\Chat;
use App\Entity\Message;
use App\Repository\ChatRepository;
use App\Repository\MessageMetaRepository;
use App\Repository\MessageRepository;
use App\Service\Chat\Loop\ChatRunDelivery;
use App\Service\Chat\Loop\ChatRunEnd;
use App\Service\Chat\Loop\ChatRunState;
use App\Tests\Unit\Service\Run\MemoryRunKeys;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ChatRunDeliveryTest extends TestCase
{
    private const RUN_ID = '01JD6Q3Z8Y9X7W6V5T4S3R2Q1P';
    private const OWNER = 7;
    private const CHAT_ID = 11;
    private const REPLY_ID = 31;

    /** @var list<object> */
    private array $persisted = [];

    public function testTheAnswerGoesIntoTheMessageTheTurnWrote(): void
    {
        $row = $this->row(self::CHAT_ID, self::OWNER);

        self::assertTrue($this->delivery($row)->deliver($this->finished()));

        self::assertSame([$row], $this->persisted);
        self::assertSame('The answer.', $row->getText());
        self::assertSame('complete', $row->getStatus());
        self::assertSame(self::RUN_ID, $row->getMeta(ChatRunDelivery::META));
    }

    public function testAMessageOfAnotherChatOrPersonIsNeverWritten(): void
    {
        foreach ([$this->row(self::CHAT_ID + 1, self::OWNER), $this->row(self::CHAT_ID, self::OWNER + 1)] as $foreign) {
            $this->persisted = [];

            $this->delivery($foreign)->deliver($this->finished());

            self::assertSame('Thinking...', $foreign->getText());
            self::assertCount(1, $this->persisted);
            self::assertNotContains($foreign, $this->persisted);
        }
    }

    public function testTheAnswerIsPostedOnce(): void
    {
        $row = $this->row(self::CHAT_ID, self::OWNER);
        $delivery = $this->delivery($row);

        self::assertTrue($delivery->deliver($this->finished()));
        self::assertFalse($delivery->deliver($this->finished()));
        self::assertCount(1, $this->persisted);
    }

    private function finished(): ChatRunState
    {
        $state = new ChatRunState(self::RUN_ID, self::OWNER, self::CHAT_ID, null, 'anthropic', 'm', 'en', '', [], 1);
        $state->replyMessageId = self::REPLY_ID;
        $state->finish(ChatRunEnd::Finished, 'The answer.');

        return $state;
    }

    private function row(int $chatId, int $userId): Message
    {
        $row = (new Message())->setUserId($userId)->setChatId($chatId)->setDirection('OUT')->setText('Thinking...')->setStatus('processing');
        (new \ReflectionProperty(Message::class, 'id'))->setValue($row, self::REPLY_ID);

        return $row;
    }

    private function delivery(Message $row): ChatRunDelivery
    {
        $chat = (new Chat())->setUserId(self::OWNER);
        (new \ReflectionProperty(Chat::class, 'id'))->setValue($chat, self::CHAT_ID);
        $chats = $this->createStub(ChatRepository::class);
        $chats->method('find')->willReturn($chat);
        $messages = $this->createStub(MessageRepository::class);
        $messages->method('find')->willReturnCallback(static fn (mixed $id): ?Message => self::REPLY_ID === $id ? $row : null);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });
        $clock = new MockClock();

        return new ChatRunDelivery(new MemoryRunKeys($clock), $chats, $messages, $this->createStub(MessageMetaRepository::class), $em, $clock);
    }
}
