<?php

declare(strict_types=1);

namespace App\AI\Stream;

/**
 * One piece an incremental decoder yields. The provider loop turns it into
 * the same callback it emitted before the decoder existed.
 */
final readonly class DecodedEvent
{
    public function __construct(
        public string $kind,
        public mixed $data = null,
    ) {
    }
}
