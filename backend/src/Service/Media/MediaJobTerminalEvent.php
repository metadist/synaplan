<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * A media job reached a terminal state and its assistant message is up to
 * date. Channels that deliver outside the browser listen for it.
 */
final readonly class MediaJobTerminalEvent
{
    public function __construct(
        public MediaJob $job,
        public int $messageId,
    ) {
    }
}
