<?php

namespace App\Message;

/**
 * Message Command für async AI-Processing.
 *
 * Queued in: async_ai_high (Redis)
 * Handled by: ProcessMessageCommandHandler
 */
class ProcessMessageCommand
{
    public function __construct(
        private int $messageId,
        private ?int $userId = null,
        private array $options = [],
    ) {
    }

    public function getMessageId(): int
    {
        return $this->messageId;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}
