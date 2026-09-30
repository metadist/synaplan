<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Index\Source;

use App\Entity\SavedTask;
use App\Repository\SavedTaskRepository;
use App\Service\SavedTask\SavedTaskConfig;
use App\Service\SmartSearch\Index\ResolvedItem;
use App\Service\SmartSearch\Index\SearchDocument;
use App\Service\SmartSearch\Index\SearchDocumentSourceInterface;

/**
 * Saved tasks by name; the kind disappears while saved tasks are off.
 */
final readonly class SavedTaskDocumentSource implements SearchDocumentSourceInterface
{
    public const KIND = 'task';

    public function __construct(
        private SavedTaskRepository $tasks,
        private SavedTaskConfig $savedTaskConfig,
    ) {
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function isEnabledFor(int $userId): bool
    {
        return $this->savedTaskConfig->isEnabled($userId);
    }

    public function refFor(object $entity, array $changeSet): ?array
    {
        if (!$entity instanceof SavedTask) {
            return null;
        }
        if ([] !== $changeSet && !array_key_exists('name', $changeSet)) {
            return null;
        }
        $id = $entity->getId();

        return null === $id ? null : ['userId' => $entity->getOwnerId(), 'refId' => (string) $id];
    }

    public function build(int $userId, string $refId): ?SearchDocument
    {
        $task = $this->tasks->find((int) $refId);
        if (!$task instanceof SavedTask || $task->getOwnerId() !== $userId) {
            return null;
        }

        return $this->document($task);
    }

    public function allForUser(int $userId): iterable
    {
        foreach ($this->tasks->findBy(['ownerId' => $userId]) as $task) {
            $document = $this->document($task);
            if (null !== $document) {
                yield $document;
            }
        }
    }

    public function resolve(int $userId, array $refIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $refIds), static fn (int $id): bool => $id > 0));
        if ([] === $ids) {
            return [];
        }

        $resolved = [];
        foreach ($this->tasks->findBy(['ownerId' => $userId, 'id' => $ids]) as $task) {
            $id = (string) $task->getId();
            $resolved[$id] = new ResolvedItem(title: $task->getName(), route: '/channels/tasks?task='.$id);
        }

        return $resolved;
    }

    private function document(SavedTask $task): ?SearchDocument
    {
        $id = $task->getId();
        if (null === $id) {
            return null;
        }

        return new SearchDocument(
            userId: $task->getOwnerId(),
            kind: self::KIND,
            refId: (string) $id,
            title: $task->getName(),
            body: '',
            updated: $task->getUpdated(),
        );
    }
}
