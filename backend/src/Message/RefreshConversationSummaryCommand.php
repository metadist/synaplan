<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Backgrounded rolling-summary refresh.
 *
 * Dispatched after a chat turn is persisted so the next turn can read a
 * fresh summary without paying for an AI call on the hot path. The handler
 * folds only the newly aged-out messages into the previous summary (or
 * bootstraps on the first refresh) via
 * {@see \App\Service\Message\ConversationSummaryService::refresh()}.
 *
 * Routed to `async_ai_high` (see `messenger.yaml`) — same queue as the rest
 * of the user-facing AI work.
 */
final readonly class RefreshConversationSummaryCommand
{
    public function __construct(
        private int $chatId,
        private int $userId,
    ) {
    }

    public function getChatId(): int
    {
        return $this->chatId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }
}
