<?php

declare(strict_types=1);

namespace App\Service\Widget\Turn;

use App\Entity\Chat;
use App\Entity\Message;

/**
 * The visitor's message as the conversation stored it: in the session's
 * chat, with the session's own files attached.
 */
final readonly class StoredVisitorMessage
{
    /**
     * @param list<int> $fileIds the attached files
     */
    public function __construct(
        public Chat $chat,
        public Message $message,
        public array $fileIds,
    ) {
    }
}
