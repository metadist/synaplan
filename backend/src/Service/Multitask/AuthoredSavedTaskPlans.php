<?php

declare(strict_types=1);

namespace App\Service\Multitask;

use App\Entity\Message;
use App\Entity\SavedTask;
use App\Repository\PromptRepository;
use App\Repository\SavedTaskRepository;
use App\Service\ModelConfigService;
use App\Service\SavedTask\Graph\SavedTaskPlanFactory;
use App\Service\SavedTask\SavedTaskConfig;
use Psr\Log\LoggerInterface;

/**
 * An enabled Saved Task with an authored graph replaces the planner.
 * No graph leaves the turn on the normal planning path.
 */
final readonly class AuthoredSavedTaskPlans
{
    public function __construct(
        private SavedTaskConfig $savedTaskConfig,
        private SavedTaskRepository $savedTasks,
        private PromptRepository $prompts,
        private SavedTaskPlanFactory $savedTaskPlanFactory,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $classification
     */
    public function plan(Message $message, array $classification): ?TaskPlanResult
    {
        try {
            $userId = $this->modelConfigService->getEffectiveUserIdForMessage($message);
        } catch (\Throwable) {
            return null;
        }

        // Anonymous channels (e.g. WhatsApp senders without an account) have no
        // per-user Saved Tasks; without this bail-out the null user id fatals in
        // findByTopicAndUser() and 500s the whole webhook.
        if (null === $userId) {
            return null;
        }

        if (!$this->savedTaskConfig->isEnabled($userId)) {
            return null;
        }

        $task = $this->taskFor($classification, $userId);
        if (null === $task || null === $task->getGraph()) {
            return null;
        }

        try {
            $plan = $this->savedTaskPlanFactory->fromTask($task, is_string($classification['language'] ?? null) ? $classification['language'] : 'en');
        } catch (\Throwable $e) {
            $this->logger->warning('TaskPlanExecutor: authored Saved Task graph rejected', [
                'message_id' => $message->getId(),
                'task_id' => $task->getId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $this->logger->info('TaskPlanExecutor: using authored Saved Task steps', [
            'message_id' => $message->getId(),
            'task_id' => $task->getId(),
            'task_name' => $task->getName(),
        ]);

        return new TaskPlanResult($plan, fallback: false, authored: true);
    }

    /**
     * A Saved Task run names its task via `saved_task_id`. Other turns resolve
     * through the prompt topic: a saved-task source replays the graph, a chat
     * turn only pins a chat-trigger task.
     *
     * @param array<string, mixed> $classification
     */
    private function taskFor(array $classification, int $userId): ?SavedTask
    {
        $taskId = $classification['saved_task_id'] ?? null;
        if (is_int($taskId) && $taskId > 0) {
            $task = $this->savedTasks->findByIdAndOwner($taskId, $userId);

            return null !== $task && $task->isEnabled() ? $task : null;
        }

        $topic = $classification['topic'] ?? null;
        if (!is_string($topic) || '' === $topic) {
            return null;
        }

        $prompt = $this->prompts->findByTopicAndUser($topic, $userId);
        if (null === $prompt || null === $prompt->getId()) {
            return null;
        }

        return 'saved_task' === ($classification['source'] ?? null)
            ? $this->savedTasks->findEnabledGraphTaskForPrompt($prompt->getId(), $userId)
            : $this->savedTasks->findEnabledChatTaskForPrompt($prompt->getId(), $userId);
    }
}
