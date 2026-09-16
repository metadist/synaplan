<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Generate a first-page poster for an office document or PDF.
 *
 * Queued in: async_index (same priority as other file-index work).
 */
final readonly class GenerateDocumentThumbnailMessage
{
    public function __construct(
        public int $fileId,
    ) {
    }
}
