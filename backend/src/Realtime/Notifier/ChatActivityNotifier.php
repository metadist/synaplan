<?php

declare(strict_types=1);

namespace App\Realtime\Notifier;

use App\Entity\Chat;
use App\Realtime\Channel\UserChannel;
use App\Realtime\Publisher\RealtimePublisherInterface;
use Psr\Log\LoggerInterface;

/**
 * Announces that a chat just received activity to the owner's per-user
 * Centrifugo channel (`user:{id}`) as a `chat.activity` event, so an open
 * browser re-sorts the chat to the top of the list the instant an
 * inbound-channel message lands (WhatsApp, email) instead of only after a
 * manual reload (#1372).
 *
 * Web-originated turns already re-sort locally through the SSE `complete`
 * handler; inbound channels have no such client round-trip, so they need this
 * push. Realtime is a best-effort enhancement on top of the persisted
 * `Chat.updatedAt` ordering — the notifier never throws, so a flaky gateway
 * can never break inbound message handling.
 */
final readonly class ChatActivityNotifier
{
    public const EVENT = 'chat.activity';

    public function __construct(
        private RealtimePublisherInterface $publisher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $direction 'IN' for an inbound message, 'OUT' for a reply
     */
    public function publishActivity(Chat $chat, int $userId, string $direction, ?string $preview = null): void
    {
        if ($userId <= 0) {
            return;
        }

        $chatId = $chat->getId();
        if (null === $chatId) {
            return;
        }

        try {
            $this->publisher->publish(
                new UserChannel($userId),
                self::EVENT,
                [
                    'chat_id' => $chatId,
                    'direction' => $direction,
                    'updated_at' => $chat->getUpdatedAt()->format(\DateTimeInterface::ATOM),
                    'preview' => null !== $preview ? mb_substr($preview, 0, 200) : null,
                ],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('ChatActivityNotifier: publish failed (ignored)', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
