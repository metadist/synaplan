<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Message\SearchIndexMessage;
use App\MessageHandler\SearchIndexMessageHandler;
use App\Service\SmartSearch\Index\SearchDocumentSourceInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Queues one Smart Search refresh per changed item after the flush commits.
 * Inserts are read in postPersist (the id exists only then); updates and
 * deletions in onFlush (the change set and the id are still there).
 *
 * A flush inside an outer transaction (wrapInTransaction, account deletion)
 * is not committed yet in postFlush. Those refreshes go out delayed and
 * marked `deferred`, so the worker re-checks instead of indexing a state it
 * cannot see; deletions never read the source at all.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class SearchIndexListener
{
    /** @var array<string, SearchIndexMessage> */
    private array $pending = [];

    /**
     * @param iterable<SearchDocumentSourceInterface> $sources
     */
    public function __construct(
        #[AutowireIterator('app.smart_search.source')]
        private readonly iterable $sources,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->consider($args->getObject(), []);
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $unit = $args->getObjectManager()->getUnitOfWork();
        foreach ($unit->getScheduledEntityUpdates() as $entity) {
            $this->consider($entity, $unit->getEntityChangeSet($entity));
        }
        foreach ($unit->getScheduledEntityDeletions() as $entity) {
            $this->consider($entity, [], removed: true);
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ([] === $this->pending) {
            return;
        }

        $messages = $this->pending;
        $this->pending = [];
        $deferred = $args->getObjectManager()->getConnection()->isTransactionActive();
        foreach ($messages as $message) {
            if ($deferred) {
                $message = new SearchIndexMessage($message->kind, $message->userId, $message->refId, $message->removed, deferred: true);
            }
            try {
                $this->bus->dispatch($message, $deferred && !$message->removed
                    ? [new DelayStamp(SearchIndexMessageHandler::DEFERRED_RETRY_DELAY_MS)]
                    : []);
            } catch (\Throwable $e) {
                $this->logger->warning('Smart Search index refresh could not be queued (app:search:reindex repairs it)', [
                    'kind' => $message->kind,
                    'user_id' => $message->userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     */
    private function consider(object $entity, array $changeSet, bool $removed = false): void
    {
        foreach ($this->sources as $source) {
            $ref = $source->refFor($entity, $changeSet);
            if (null === $ref || $ref['userId'] <= 0) {
                continue;
            }
            $key = $source->kind().':'.$ref['userId'].':'.$ref['refId'];
            $this->pending[$key] = new SearchIndexMessage($source->kind(), $ref['userId'], $ref['refId'], $removed);
        }
    }
}
