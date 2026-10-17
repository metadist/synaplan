<?php

declare(strict_types=1);

namespace App\Service\Email\Turn;

/**
 * Where an inbound email sits in its mail thread: its own message id (the
 * reply answers it), the message it answers, and the normalized id the
 * idempotency check knows it by.
 */
final readonly class EmailThread
{
    public function __construct(
        public ?string $messageId,
        public ?string $inReplyTo,
        public ?string $externalId,
    ) {
    }
}
