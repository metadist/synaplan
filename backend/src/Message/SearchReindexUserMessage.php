<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Build one user's Smart Search index from scratch (first search after an
 * upgrade finds an empty index).
 *
 * Queued in: async_index
 * Handled by: SearchReindexUserMessageHandler
 */
final readonly class SearchReindexUserMessage
{
    public function __construct(
        public int $userId,
    ) {
    }
}
