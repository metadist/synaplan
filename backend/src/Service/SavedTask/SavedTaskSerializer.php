<?php

declare(strict_types=1);

namespace App\Service\SavedTask;

use App\Entity\SavedTask;
use App\Entity\SavedTaskRun;
use App\Service\SavedTask\Graph\SavedTaskSummary;

final readonly class SavedTaskSerializer
{
    public function __construct(
        private SavedTaskSummary $summary,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function task(SavedTask $task): array
    {
        $summary = $this->summary->describe($task);

        return [
            'id' => $task->getId(),
            'promptId' => $task->getPromptId(),
            'name' => $task->getName(),
            'enabled' => $task->isEnabled(),
            'triggerType' => $task->getTriggerType(),
            'triggerConfig' => $task->getTriggerConfig(),
            'graph' => $task->getGraph(),
            'allowUnattended' => $task->allowsUnattended(),
            'chatId' => $task->getChatId(),
            'nextRunAt' => $task->getNextRunAt()?->format(\DateTimeInterface::ATOM),
            'lastRunAt' => $task->getLastRunAt()?->format(\DateTimeInterface::ATOM),
            'consecutiveFailures' => $task->getConsecutiveFailures(),
            'autoPaused' => $task->isAutoPaused(),
            'summary' => $summary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function run(SavedTaskRun $run): array
    {
        return [
            'id' => $run->getId(),
            'status' => $run->getStatus(),
            'trigger' => $run->getTrigger(),
            'messageId' => $run->getMessageId(),
            'planSnapshot' => $run->getPlanSnapshot(),
            'error' => $run->getError(),
            'started' => $run->getStarted()?->format(\DateTimeInterface::ATOM),
            'finished' => $run->getFinished()?->format(\DateTimeInterface::ATOM),
            'created' => $run->getCreated(),
        ];
    }
}
