<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Refresh one Smart Search index row after its item changed.
 *
 * Queued in: async_index
 * Handled by: SearchIndexMessageHandler
 */
final readonly class SearchIndexMessage
{
    public function __construct(
        public string $kind,
        public int $userId,
        public string $refId,
    ) {
    }
}
