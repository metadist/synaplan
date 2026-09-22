<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Ask the worker to tell the owner and current share subjects that a chat
 * turn finished. Dispatched after the row is committed so the HTTP stream
 * does not wait on the realtime gateway.
 */
final readonly class PublishChatActivity
{
    public function __construct(private int $chatId)
    {
    }

    public function getChatId(): int
    {
        return $this->chatId;
    }
}
