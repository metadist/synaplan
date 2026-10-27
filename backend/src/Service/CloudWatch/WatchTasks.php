<?php

declare(strict_types=1);

namespace App\Service\CloudWatch;

use App\Entity\CloudWatch;
use App\Entity\SavedTask;
use App\Repository\SavedTaskRepository;

/**
 * The task a watch runs, and putting it back when the watch stops.
 */
final readonly class WatchTasks
{
    public function __construct(private SavedTaskRepository $tasks)
    {
    }

    /** @return list<array{id: int, name: string}> */
    public function manual(int $ownerId): array
    {
        $out = [];
        foreach ($this->tasks->findManual($ownerId) as $task) {
            $out[] = ['id' => (int) $task->getId(), 'name' => $task->getName()];
        }

        return $out;
    }

    public function requireManual(int $ownerId, int $taskId): SavedTask
    {
        $task = $this->tasks->findByIdAndOwner($taskId, $ownerId);
        if (!$task instanceof SavedTask || SavedTask::TRIGGER_MANUAL !== $task->getTriggerType()) {
            throw new CloudWatchException(422, 'That task cannot be used. Pick a task you start yourself.');
        }

        return $task;
    }

    public function bind(SavedTask $task, int $watchId): void
    {
        $task->setTrigger(SavedTask::TRIGGER_FILE_EVENT, ['watch_id' => $watchId]);
    }

    public function release(CloudWatch $watch): void
    {
        $taskId = $watch->getTaskId();
        if (null === $taskId) {
            return;
        }
        $task = $this->tasks->findByIdAndOwner($taskId, $watch->getOwnerId());
        if (!$task instanceof SavedTask) {
            return;
        }
        if (CloudWatch::PRESET_TASK === $watch->getPreset()) {
            $task->setTrigger(SavedTask::TRIGGER_MANUAL, null);

            return;
        }
        $task->setEnabled(false);
    }

    public function name(CloudWatch $watch): ?string
    {
        $taskId = $watch->getTaskId();
        if (null === $taskId) {
            return null;
        }
        $task = $this->tasks->findByIdAndOwner($taskId, $watch->getOwnerId());

        return $task instanceof SavedTask ? $task->getName() : null;
    }
}
