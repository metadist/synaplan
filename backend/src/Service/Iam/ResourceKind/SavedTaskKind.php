<?php

declare(strict_types=1);

namespace App\Service\Iam\ResourceKind;

use App\Entity\SavedTask;
use App\Repository\SavedTaskRepository;
use App\Service\Iam\Permission;

/**
 * Saved task identity is BSAVEDTASKS.BID. Shareable permissions: read, use
 * (use = run a copy owned by the member).
 */
final readonly class SavedTaskKind implements ShareableResourceKindInterface
{
    public const KEY = 'saved_task';

    public function __construct(
        private SavedTaskRepository $savedTaskRepository,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function ownerId(string $resourceId): ?int
    {
        $task = $this->findTask($resourceId);

        return $task?->getOwnerId();
    }

    public function describe(string $resourceId): ResourceCard
    {
        $task = $this->findTask($resourceId);
        if (null === $task) {
            return new ResourceCard($resourceId, $resourceId, 'task');
        }

        return new ResourceCard(
            (string) $task->getId(),
            $task->getName(),
            'task',
            [
                'ownerId' => $task->getOwnerId(),
                'promptId' => $task->getPromptId(),
                'triggerType' => $task->getTriggerType(),
                'lastRunAt' => $task->getLastRunAt()?->format(\DateTimeInterface::ATOM),
            ],
        );
    }

    public function listOwnedBy(int $userId): iterable
    {
        foreach ($this->savedTaskRepository->findByOwner($userId) as $task) {
            yield new ResourceCard(
                (string) $task->getId(),
                $task->getName(),
                'task',
                [
                    'ownerId' => $task->getOwnerId(),
                    'promptId' => $task->getPromptId(),
                    'triggerType' => $task->getTriggerType(),
                ],
            );
        }
    }

    public function onShareChanged(string $resourceId): void
    {
    }

    public function supportedPermissions(): array
    {
        return [Permission::Read, Permission::Use];
    }

    private function findTask(string $resourceId): ?SavedTask
    {
        if ('' === $resourceId || !ctype_digit($resourceId)) {
            return null;
        }
        $task = $this->savedTaskRepository->find((int) $resourceId);

        return $task instanceof SavedTask ? $task : null;
    }
}
