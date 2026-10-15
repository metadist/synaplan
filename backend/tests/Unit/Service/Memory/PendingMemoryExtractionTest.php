<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Memory;

use App\Entity\Message;
use App\Message\ExtractMemoriesCommand;
use App\Service\Memory\MemoryExtractionDispatcher;
use App\Service\Memory\PendingMemoryExtraction;
use App\Service\Message\PerfPipelineFlag;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The memory job references the stored message by id. A message that was
 * never stored — incognito, or live voice when the transcript row could not
 * be written — gets no job, so the turn it belongs to is never broken by it.
 */
final class PendingMemoryExtractionTest extends TestCase
{
    public function testAStoredMessageGetsAJob(): void
    {
        $command = $this->extraction()->build($this->message(1001), 'Answer', [['role' => 'user', 'content' => 'Hi']], [], false, false);

        self::assertInstanceOf(ExtractMemoriesCommand::class, $command);
        self::assertSame(1001, $command->getMessageId());
    }

    public function testAnIncognitoMessageGetsNoJob(): void
    {
        self::assertNull($this->extraction()->build($this->message(null), 'Answer', [], [], false, false, incognito: true));
    }

    public function testAMessageThatWasNeverStoredGetsNoJob(): void
    {
        self::assertNull($this->extraction()->build($this->message(null), 'Answer', [], [], false, false));
    }

    public function testTheChatTurnOfAMessageThatWasNeverStoredDispatchesNothing(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $deferred = $this->extraction($bus)->afterTurn($this->message(null), 'Answer', [], [], []);

        self::assertNull($deferred);
    }

    public function testTheChatTurnOfAStoredMessageDispatchesItsJob(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $this->extraction($bus)->afterTurn($this->message(1001), 'Answer', [], [], []);
    }

    private function extraction(?MessageBusInterface $bus = null): PendingMemoryExtraction
    {
        $perfFlag = $this->createStub(PerfPipelineFlag::class);
        $perfFlag->method('isEnabled')->willReturn(true);

        return new PendingMemoryExtraction(
            new MemoryExtractionDispatcher($bus ?? $this->createStub(MessageBusInterface::class), new NullLogger()),
            $perfFlag,
            new NullLogger(),
        );
    }

    private function message(?int $id): Message
    {
        $message = $this->createStub(Message::class);
        $message->method('getId')->willReturn($id);
        $message->method('getUserId')->willReturn(7);

        return $message;
    }
}
