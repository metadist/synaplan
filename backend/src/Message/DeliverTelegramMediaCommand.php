<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Sends the outcome of a finished media job to the Telegram chat that
 * asked for it.
 */
final readonly class DeliverTelegramMediaCommand
{
    public function __construct(
        private string $jobKey,
        private int $messageId,
    ) {
    }

    public function getJobKey(): string
    {
        return $this->jobKey;
    }

    public function getMessageId(): int
    {
        return $this->messageId;
    }
}
