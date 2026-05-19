<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Message\ExtractMemoriesCommand;
use App\Service\MemoryExtractionDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Unit coverage for {@see MemoryExtractionDispatcher}.
 *
 * Pins the dispatch + log + swallow-on-failure contract that both the
 * synchronous {@see \App\Service\Message\Handler\ChatHandler} fallback
 * and the deferred {@see \App\Controller\StreamController} SSE path now
 * delegate to (Copilot review of PR #939: the two callers must not
 * grow drift in logging, retry semantics, or future middleware).
 */
final class MemoryExtractionDispatcherTest extends TestCase
{
    private MessageBusInterface&MockObject $messageBus;
    private LoggerInterface&MockObject $logger;
    private MemoryExtractionDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->dispatcher = new MemoryExtractionDispatcher($this->messageBus, $this->logger);
    }

    public function testDispatchForwardsCommandToBusExactlyOnce(): void
    {
        $command = new ExtractMemoriesCommand(
            messageId: 99,
            userId: 7,
            aiResponse: 'reply text',
            threadSnapshot: [['role' => 'user', 'content' => 'hi']],
            relevantMemories: [],
        );

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->identicalTo($command))
            ->willReturn(new Envelope($command));

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('Dispatched ExtractMemoriesCommand'),
                $this->callback(function (array $context): bool {
                    return 99 === $context['message_id']
                        && 7 === $context['user_id']
                        && 1 === $context['thread_length'];
                })
            );

        $this->dispatcher->dispatch($command);
    }

    public function testDispatchIsNoopForNullCommand(): void
    {
        $this->messageBus->expects($this->never())->method('dispatch');
        $this->logger->expects($this->never())->method('info');
        $this->logger->expects($this->never())->method('warning');

        $this->dispatcher->dispatch(null);
    }

    /**
     * The dispatcher MUST swallow bus failures: the user-facing SSE
     * `complete` event is already on the wire by the time we get here,
     * and a worker hiccup must not bubble back as an unhandled
     * exception that crashes the controller branch and loses the
     * assistant response.
     */
    public function testDispatchSwallowsBusFailuresAndLogsAtWarning(): void
    {
        $command = new ExtractMemoriesCommand(
            messageId: 42,
            userId: 1,
            aiResponse: '',
            threadSnapshot: [],
            relevantMemories: [],
        );

        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->willThrowException(new \RuntimeException('messenger transport down'));

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Failed to dispatch ExtractMemoriesCommand'),
                $this->callback(function (array $context): bool {
                    return 42 === $context['message_id']
                        && 'messenger transport down' === $context['error'];
                })
            );

        // Must not throw.
        $this->dispatcher->dispatch($command);
    }
}
