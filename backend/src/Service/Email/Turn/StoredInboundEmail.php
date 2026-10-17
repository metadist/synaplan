<?php

declare(strict_types=1);

namespace App\Service\Email\Turn;

use App\Entity\Chat;
use App\Entity\Message;

/**
 * The inbound email as the conversation stored it: a message in the
 * sender's email chat, with its metadata and attachments.
 */
final readonly class StoredInboundEmail
{
    public function __construct(
        public Chat $chat,
        public Message $message,
    ) {
    }
}
