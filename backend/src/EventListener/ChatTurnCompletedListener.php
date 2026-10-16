<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Message;
use App\Message\PublishChatActivity;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A finished assistant reply is one event, queued after the row is committed.
 * Streaming flushes (status still processing) and the inbound row of the same
 * turn stay quiet, so watchers reload once when the answer is saved.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class ChatTurnCompletedListener
{
    /** @var list<int> */
    private array $pending = [];

    public function __construct(
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
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

        $chatIds = array_values(array_unique($this->pending));
        $this->pending = [];
        foreach ($chatIds as $chatId) {
            try {
                $this->bus->dispatch(new PublishChatActivity($chatId));
            } catch (\Throwable $e) {
                $this->logger->warning('Chat turn activity dispatch failed (ignored)', [
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     */
    private function consider(object $entity, array $changeSet): void
    {
        if (!$entity instanceof Message || 'OUT' !== $entity->getDirection() || 'complete' !== $entity->getStatus()) {
            return;
        }
        // A first save, a status flip to complete, or a later append onto an
        // already-complete reply (continuations flush text without touching
        // status). Other updates of a finished row stay quiet.
        $becameComplete = [] === $changeSet
            || (isset($changeSet['status']) && 'complete' === $changeSet['status'][1]);
        $textAppended = false;
        if (isset($changeSet['text'])) {
            $previousText = is_string($changeSet['text'][0] ?? null) ? $changeSet['text'][0] : '';
            $currentText = is_string($changeSet['text'][1] ?? null) ? $changeSet['text'][1] : '';
            $textAppended = mb_strlen($currentText) > mb_strlen($previousText);
        }
        if (!$becameComplete && !$textAppended) {
            return;
        }

        $chatId = $entity->getChatId();
        if (null === $chatId || $chatId <= 0) {
            return;
        }

        $this->pending[] = $chatId;
    }
}
