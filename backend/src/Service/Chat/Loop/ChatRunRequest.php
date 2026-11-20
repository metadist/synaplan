<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

/**
 * What a chat turn hands to a new run: who pays and who asked, the chat and
 * its incoming message, the prompt, and the job text for the run's row.
 */
final readonly class ChatRunRequest
{
    public function __construct(
        public int $userId,
        public int $startedBy,
        public int $chatId,
        public ?int $incomingMessageId,
        public ChatRunPrompt $prompt,
        public string $job,
        public ?string $idempotencyKey = null,
    ) {
    }
}
