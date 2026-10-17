<?php

namespace App\Service\WhatsApp\Turn;

use App\Entity\Chat;
use App\Entity\Message;

/**
 * The inbound WhatsApp message as the conversation stored it, in the
 * sender's WhatsApp chat with its media.
 */
final readonly class StoredWhatsAppMessage
{
    public function __construct(
        public Chat $chat,
        public Message $message,
    ) {
    }
}
