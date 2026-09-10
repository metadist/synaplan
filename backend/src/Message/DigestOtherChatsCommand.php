<?php

declare(strict_types=1);

namespace App\Message;

/**
 * After-turn digest of the user's *other* chats.
 *
 * The live chat still respects QUIET_SECONDS (it is being written). Messages
 * in every other chat are already "left" and must be indexable immediately
 * so a follow-up in a new window can find them.
 *
 * Routed to `async_ai_high` (see `messenger.yaml`).
 */
final readonly class DigestOtherChatsCommand
{
    public function __construct(
        private int $userId,
        private int $liveChatId,
    ) {
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getLiveChatId(): int
    {
        return $this->liveChatId;
    }
}
