<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RefreshConversationSummaryCommand;
use App\Service\Message\ConversationSummaryService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler for {@see RefreshConversationSummaryCommand}.
 *
 * Runs on the messenger worker so the user's streaming connection never
 * waits on the summarizer. Transient AI / Redis failures are re-thrown so
 * Messenger can retry (see `messenger.yaml`).
 */
#[AsMessageHandler]
final readonly class RefreshConversationSummaryCommandHandler
{
    public function __construct(
        private ConversationSummaryService $conversationSummaryService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RefreshConversationSummaryCommand $command): void
    {
        $chatId = $command->getChatId();
        $userId = $command->getUserId();

        try {
            $wrote = $this->conversationSummaryService->refresh($chatId, $userId);
            $this->logger->info('RefreshConversationSummaryCommand: finished', [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'wrote' => $wrote,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('RefreshConversationSummaryCommand: failed', [
                'chat_id' => $chatId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
