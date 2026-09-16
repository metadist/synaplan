<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Refresh the owner-0 / SYSTEM:synaplan documentation corpus.
 *
 * Dispatched when `app:updates:check` records a new published version;
 * the daily scheduler also runs the command directly.
 */
final readonly class SyncPlatformDocsMessage
{
    public function __construct(
        public bool $force = false,
    ) {
    }
}
