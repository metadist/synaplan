<?php

declare(strict_types=1);

namespace App\Service;

use App\Message\RefreshConversationSummaryCommand;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Forwards a {@see RefreshConversationSummaryCommand} to the messenger bus.
 *
 * Bus failures are logged and never re-thrown — a missed refresh is
 * recovered on the next turn; corrupting the user-facing response is not.
 */
final readonly class ConversationSummaryRefreshDispatcher
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function dispatch(int $chatId, int $userId): void
    {
        if ($chatId <= 0 || $userId <= 0) {
            return;
        }

        try {
            $this->messageBus->dispatch(new RefreshConversationSummaryCommand($chatId, $userId));

            $this->logger->info('ConversationSummaryRefreshDispatcher: dispatched', [
                'chat_id' => $chatId,
                'user_id' => $userId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('ConversationSummaryRefreshDispatcher: dispatch failed', [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
