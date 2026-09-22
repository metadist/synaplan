<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Message;
use App\Realtime\Notifier\ChatAudienceNotifier;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * A finished turn is one event, published after the row is committed, to the
 * owner and the people the chat is shared with. Streaming flushes (status
 * still processing) stay quiet so watchers reload once, not per token.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class ChatTurnCompletedListener
{
    /** @var list<array{chat: \App\Entity\Chat, direction: string, preview: ?string}> */
    private array $pending = [];

    public function __construct(private ChatAudienceNotifier $audience)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $unit = $args->getObjectManager()->getUnitOfWork();
        foreach ($unit->getScheduledEntityInsertions() as $entity) {
            $this->consider($entity, []);
        }
        foreach ($unit->getScheduledEntityUpdates() as $entity) {
            $this->consider($entity, $unit->getEntityChangeSet($entity));
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ([] === $this->pending) {
            return;
        }

        $batch = $this->pending;
        $this->pending = [];
        $seen = [];
        foreach ($batch as $item) {
            $chatId = $item['chat']->getId();
            if (null === $chatId || isset($seen[$chatId])) {
                continue;
            }
            $seen[$chatId] = true;
            $this->audience->publish($item['chat'], $item['direction'], $item['preview']);
        }
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     */
    private function consider(object $entity, array $changeSet): void
    {
        if (!$entity instanceof Message || 'complete' !== $entity->getStatus()) {
            return;
        }
        if ([] !== $changeSet && (!isset($changeSet['status']) || 'complete' !== $changeSet['status'][1])) {
            return;
        }

        $chat = $entity->getChat();
        if (null === $chat) {
            return;
        }

        $text = trim($entity->getText());
        $this->pending[] = [
            'chat' => $chat,
            'direction' => 'OUT' === $entity->getDirection() ? 'OUT' : 'IN',
            'preview' => '' === $text ? null : mb_substr($text, 0, 240),
        ];
    }
}
