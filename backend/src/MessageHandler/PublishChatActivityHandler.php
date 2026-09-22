<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PublishChatActivity;
use App\Realtime\Notifier\ChatAudienceNotifier;
use App\Repository\ChatRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Re-reads the chat and its live shares, then publishes one activity event.
 * Running here, off the request, means a slow gateway cannot hold the reply
 * open, and a share revoked since the turn was saved is no longer included.
 */
#[AsMessageHandler]
final readonly class PublishChatActivityHandler
{
    public function __construct(
        private ChatRepository $chats,
        private ChatAudienceNotifier $audience,
    ) {
    }

    public function __invoke(PublishChatActivity $message): void
    {
        $chat = $this->chats->find($message->getChatId());
        if (null === $chat) {
            return;
        }

        $this->audience->publish($chat, 'OUT');
    }
}
