<?php

declare(strict_types=1);

namespace App\Service\SavedTask;

use App\Entity\SavedTask;
use App\Entity\SavedTaskRun;
use App\Entity\User;
use App\Repository\SavedTaskRepository;
use App\Repository\SavedTaskRunRepository;
use App\Repository\UserRepository;
use App\Service\Multitask\Execution\DagExecutor;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeContextRehydrator;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskPlan;
use App\Service\Multitask\TaskPlanStore;
use App\Service\RateLimitService;
use App\Service\SavedTask\Graph\SavedTaskPlanFactory;
use Psr\Log\LoggerInterface;

/**
 * Continues a saved task run that paused on its research step: with the
 * report, or by failing the step with the outcome sentence. A run that is no
 * longer waiting on that step is left alone, so a repeated hand-over never
 * starts the next step twice.
 */
final readonly class SavedTaskResearchContinuation
{
    private const RESULT_IN_CHAT = 'The research result is in the chat.';

    public function __construct(
        private SavedTaskRunRepository $runs,
        private SavedTaskRepository $tasks,
        private UserRepository $users,
        private SavedTaskPlanFactory $planFactory,
        private NodeContextRehydrator $rehydrator,
        private DagExecutor $dagExecutor,
        private TaskPlanStore $planStore,
        private RateLimitService $rateLimits,
        private SavedTaskResumeReply $reply,
        private LoggerInterface $logger,
    ) {
    }

    /** Queued or running: the run has not reached a pause or an end yet. */
    public function isActive(int $runId): bool
    {
        $run = $this->runs->find($runId);

        return $run instanceof SavedTaskRun
            && in_array($run->getStatus(), [SavedTaskRun::STATUS_QUEUED, SavedTaskRun::STATUS_RUNNING], true);
    }

    /**
     * @param int                  $researchOwnerId the person who owns the research run
     * @param array<string, mixed> $args            `research_report` (+ `research_files`) or `research_failed`
     */
    public function complete(int $runId, string $nodeId, int $researchOwnerId, array $args): ?SavedTaskRun
    {
        $run = $this->waitingOn($runId, $nodeId);
        if (null === $run) {
            return null;
        }
        $task = $this->tasks->find($run->getSavedTaskId());
        $user = $task instanceof SavedTask ? $this->users->find($task->getOwnerId()) : null;
        if (!$task instanceof SavedTask || !$user instanceof User) {
            return $this->stop($run, 'This saved task could not continue after the research. '.self::RESULT_IN_CHAT);
        }
        if ($task->getOwnerId() !== $researchOwnerId) {
            $this->logger->warning('Research run does not belong to the saved task owner', ['run_id' => $runId, 'node_id' => $nodeId]);

            return null;
        }
        $limit = $this->rateLimits->checkLimit($user, 'MESSAGES');
        if (empty($limit['allowed'])) {
            return $this->stop($run, 'Your usage limit was reached, so the steps after the research did not run. '.self::RESULT_IN_CHAT, $task);
        }
        $plan = $this->planFactory->fromTask($task);
        $node = $plan->nodeById($nodeId);
        if (null === $node || Capability::Research !== $node->capability) {
            return $this->stop($run, 'This step is no longer a research step, so the saved task stopped. '.self::RESULT_IN_CHAT, $task);
        }

        try {
            $assembled = $this->dagExecutor->resume($plan, $this->context($run, $task), $nodeId, $args);
        } catch (\Throwable $e) {
            $this->logger->warning('Saved task did not continue after research', [
                'run_id' => $runId,
                'node_id' => $nodeId,
                'exception' => $e,
            ]);

            return $this->stop($run, 'The research finished, but the next step did not run. '.self::RESULT_IN_CHAT, $task);
        }

        // The next steps already ran. A retry from here would run them (and send mail) twice.
        try {
            return $this->settle($run, $task, $plan, $nodeId, $args, $assembled);
        } catch (\Throwable $e) {
            $this->logger->error('Saved task ran after research, but the run was not recorded', [
                'run_id' => $runId,
                'node_id' => $nodeId,
                'exception' => $e,
            ]);

            return $this->stop($run, 'The steps after the research ran, but their result was not saved. Check the chat before you start the task again.', $task);
        }
    }

    /** Fail a run that still waits on this research step; any other run is left alone. */
    public function fail(int $runId, string $nodeId, string $error): void
    {
        $run = $this->waitingOn($runId, $nodeId);
        if (null === $run) {
            return;
        }
        $task = $this->tasks->find($run->getSavedTaskId());
        $this->stop($run, $error, $task instanceof SavedTask ? $task : null);
    }

    private function waitingOn(int $runId, string $nodeId): ?SavedTaskRun
    {
        $run = $this->runs->find($runId);
        if (!$run instanceof SavedTaskRun
            || SavedTaskRun::STATUS_WAITING_APPROVAL !== $run->getStatus()
            || $run->getWaitingNode() !== $nodeId) {
            return null;
        }

        return $run;
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $assembled
     */
    private function settle(SavedTaskRun $run, SavedTask $task, TaskPlan $plan, string $nodeId, array $args, array $assembled): SavedTaskRun
    {
        $messageId = $run->getMessageId();
        $statuses = [];
        $rawStatuses = $assembled['node_statuses'] ?? null;
        if (is_array($rawStatuses)) {
            foreach ($rawStatuses as $id => $status) {
                if (is_string($status)) {
                    $statuses[(string) $id] = $status;
                }
            }
        }
        if (null !== $messageId) {
            $this->planStore->persistWithStatuses($messageId, $plan, null, $statuses);
        }
        if ('failed' === ($statuses[$nodeId] ?? '')) {
            $sentence = $args['research_failed'] ?? null;

            return $this->stop(
                $run,
                is_string($sentence) && '' !== trim($sentence) ? trim($sentence) : 'The research did not finish, so the next step did not run.',
                $task,
            );
        }
        $report = is_string($args['research_report'] ?? null) ? $args['research_report'] : '';
        $this->reply->post($task, $assembled, $report);
        $waiting = $this->waitingNode($statuses);
        if (null !== $waiting) {
            $run->markWaitingApproval($waiting, $messageId, $run->getPlanSnapshot());
            $this->runs->save($run);

            return $run;
        }
        if (!empty($assembled['all_failed'])) {
            return $this->stop($run, 'A step failed after the research finished. '.self::RESULT_IN_CHAT, $task);
        }
        $run->markCompleted($messageId, $run->getPlanSnapshot());
        $run->clearWaitingNode();
        $task->recordSuccess();
        $this->runs->save($run);
        $this->tasks->save($task);
        $this->logger->info('Saved task continued after research', [
            'run_id' => $run->getId(),
            'node_id' => $nodeId,
        ]);

        return $run;
    }

    /**
     * @param array<string, string> $statuses
     */
    private function waitingNode(array $statuses): ?string
    {
        foreach ($statuses as $nodeId => $status) {
            if ('waiting_approval' === $status) {
                return $nodeId;
            }
        }

        return null;
    }

    private function context(SavedTaskRun $run, SavedTask $task): NodeContext
    {
        // Nobody is watching when research ends, so later steps run under the unattended policy.
        $options = [
            'saved_task' => true,
            'saved_task_id' => (int) $task->getId(),
            'saved_task_run_id' => (int) $run->getId(),
            'allow_unattended' => $task->allowsUnattended(),
            'saved_task_name' => $task->getName(),
        ];
        $snapshot = $run->getPlanSnapshot();
        $triggerPayload = is_array($snapshot) ? ($snapshot['trigger_payload'] ?? null) : null;
        if (is_array($triggerPayload) && [] !== $triggerPayload) {
            $options['trigger_payload'] = $triggerPayload;
        }

        return $this->rehydrator->fromRun($run, $options);
    }

    private function stop(SavedTaskRun $run, string $error, ?SavedTask $task = null): SavedTaskRun
    {
        $run->markFailed($error, $run->getMessageId(), $run->getPlanSnapshot());
        $run->clearWaitingNode();
        $this->runs->save($run);
        if ($task instanceof SavedTask) {
            $task->recordFailure();
            $this->tasks->save($task);
        }

        return $run;
    }
}
