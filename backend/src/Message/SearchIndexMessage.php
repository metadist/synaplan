<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Refresh one Smart Search index row after its item changed.
 *
 * `removed`: the item was deleted, drop the row without reading the source.
 * `deferred`: queued while an outer transaction was still open, so the
 * worker may not see the change yet; the handler re-checks a few times
 * instead of treating an invisible item as gone.
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
        public bool $removed = false,
        public bool $deferred = false,
        public int $attempt = 0,
    ) {
    }

    public function nextAttempt(): self
    {
        return new self($this->kind, $this->userId, $this->refId, $this->removed, $this->deferred, $this->attempt + 1);
    }
}
