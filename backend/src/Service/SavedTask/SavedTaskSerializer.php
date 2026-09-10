<?php

declare(strict_types=1);

namespace App\Service\SavedTask;

use App\Entity\Prompt;
use App\Entity\SavedTask;
use App\Entity\SavedTaskRun;
use App\Repository\PromptRepository;
use App\Repository\SavedTaskRunRepository;
use App\Service\SavedTask\Graph\SavedTaskSummary;

final readonly class SavedTaskSerializer
{
    /**
     * The card shows the start of the instruction so users can tell WHAT runs
     * without opening the prompt. Kept short enough for a single card line.
     */
    private const PREVIEW_LENGTH = 60;

    public function __construct(
        private SavedTaskSummary $summary,
        private PromptRepository $prompts,
        private ?SavedTaskRunRepository $runs = null,
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
            'triggerConfig' => $this->publicTriggerConfig($task),
            'graph' => $task->getGraph(),
            'allowUnattended' => $task->allowsUnattended(),
            'chatId' => $task->getChatId(),
            'nextRunAt' => $task->getNextRunAt()?->format(\DateTimeInterface::ATOM),
            'lastRunAt' => $task->getLastRunAt()?->format(\DateTimeInterface::ATOM),
            'consecutiveFailures' => $task->getConsecutiveFailures(),
            'autoPaused' => $task->isAutoPaused(),
            'summary' => $summary,
            'instructionPreview' => $this->instructionPreview($task->getPromptId()),
            'waitingApprovalCount' => $this->waitingApprovalCount($task),
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
            'waitingNode' => $run->getWaitingNode(),
        ];
    }

    /**
     * Never expose HMAC secrets. The webhook URL is reconstructed by the client
     * from the public token.
     *
     * @return array<string, mixed>|null
     */
    private function publicTriggerConfig(SavedTask $task): ?array
    {
        $config = $task->getTriggerConfig();
        if (null === $config) {
            return null;
        }
        $hasSecret = is_string($config['hmacSecret'] ?? null) && '' !== $config['hmacSecret'];
        unset($config['hmacSecret']);
        if (SavedTask::TRIGGER_WEBHOOK === $task->getTriggerType()) {
            $config['hmacConfigured'] = $hasSecret;
        }

        return $config;
    }

    private function instructionPreview(int $promptId): ?string
    {
        $prompt = $this->prompts->find($promptId);
        if (!$prompt instanceof Prompt) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $prompt->getPrompt()));
        if ('' === $text) {
            return null;
        }
        if (mb_strlen($text) <= self::PREVIEW_LENGTH) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, self::PREVIEW_LENGTH)).'…';
    }

    private function waitingApprovalCount(SavedTask $task): int
    {
        $taskId = $task->getId();
        if (null === $taskId || null === $this->runs) {
            return 0;
        }

        return $this->runs->countWaitingForTask($taskId);
    }
}
