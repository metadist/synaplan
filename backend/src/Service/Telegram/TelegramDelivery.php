<?php

declare(strict_types=1);

namespace App\Service\Telegram;

/**
 * What reached Telegram for one reply.
 */
final readonly class TelegramDelivery
{
    /**
     * @param list<int>                  $messageIds Telegram ids of every message sent
     * @param list<TelegramOutgoingFile> $unsent     files that stay in Synaplan only
     * @param bool                       $textOnly   true when nothing but text was sent, so it can be edited later
     */
    public function __construct(
        public array $messageIds,
        public array $unsent,
        public bool $textOnly,
    ) {
    }

    public function lastMessageId(): ?int
    {
        return [] === $this->messageIds ? null : $this->messageIds[count($this->messageIds) - 1];
    }
}
