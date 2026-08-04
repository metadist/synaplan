<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

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
        $this->bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static function (RefreshConversationSummaryCommand $cmd): bool {
                return 42 === $cmd->getChatId() && 7 === $cmd->getUserId();
            }))
            ->willReturnCallback(static fn (object $msg): Envelope => new Envelope($msg));

        $this->dispatcher->dispatch(42, 7);
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
