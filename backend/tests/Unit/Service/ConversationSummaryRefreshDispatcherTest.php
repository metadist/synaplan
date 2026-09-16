<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Message\DigestOtherChatsCommand;
use App\Message\RefreshConversationSummaryCommand;
use App\Service\ConversationSummaryRefreshDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ConversationSummaryRefreshDispatcherTest extends TestCase
{
    private MessageBusInterface&MockObject $bus;
    private ConversationSummaryRefreshDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->dispatcher = new ConversationSummaryRefreshDispatcher($this->bus, new NullLogger());
    }

    public function testDispatchSendsTheCommand(): void
    {
        $dispatched = [];
        $this->bus->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (object $msg) use (&$dispatched): Envelope {
                $dispatched[] = $msg;

                return new Envelope($msg);
            });

        $this->dispatcher->dispatch(42, 7);

        self::assertInstanceOf(RefreshConversationSummaryCommand::class, $dispatched[0]);
        self::assertSame(42, $dispatched[0]->getChatId());
        self::assertSame(7, $dispatched[0]->getUserId());
        self::assertInstanceOf(DigestOtherChatsCommand::class, $dispatched[1]);
        self::assertSame(7, $dispatched[1]->getUserId());
        self::assertSame(42, $dispatched[1]->getLiveChatId());
    }

    public function testDispatchNoOpsOnInvalidIds(): void
    {
        $this->bus->expects($this->never())->method('dispatch');

        $this->dispatcher->dispatch(0, 7);
        $this->dispatcher->dispatch(42, 0);
    }

    public function testDispatchSwallowsBusFailures(): void
    {
        $this->bus->method('dispatch')->willThrowException(new \RuntimeException('bus down'));

        $this->dispatcher->dispatch(42, 7);

        $this->addToAssertionCount(1);
    }
}
