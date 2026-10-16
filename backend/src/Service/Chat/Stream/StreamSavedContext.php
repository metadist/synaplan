<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

/**
 * Whether a turn may read what the person already saved.
 *
 * Both stay on unless the person turns them off before the first message
 * of an incognito chat. A normal chat never sets either flag.
 */
final readonly class StreamSavedContext
{
    public function __construct(
        public bool $disableMemories = false,
        public bool $disableKnowledge = false,
    ) {
    }
}
