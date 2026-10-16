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
     * @param list<TelegramOutgoingFile> $tooLarge   files above the Telegram upload limit
     * @param list<TelegramOutgoingFile> $failed     files that are missing or Telegram refused
     * @param bool                       $textOnly   true when nothing but text was sent, so it can be edited later
     */
    public function __construct(
        public array $messageIds,
        public array $tooLarge,
        public array $failed,
        public bool $textOnly,
    ) {
    }

    public function lastMessageId(): ?int
    {
        return [] === $this->messageIds ? null : $this->messageIds[count($this->messageIds) - 1];
    }

    /**
     * The translation key of the sentence about files that stayed in
     * Synaplan, or null when every file reached Telegram.
     */
    public function unsentKey(): ?string
    {
        if ([] !== $this->failed) {
            return 'file_not_sent';
        }

        return [] !== $this->tooLarge ? 'file_too_large_to_send' : null;
    }
}
