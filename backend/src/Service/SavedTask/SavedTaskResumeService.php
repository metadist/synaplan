<?php

declare(strict_types=1);

namespace App\Service\SavedTask;

use App\Entity\Approval;
use App\Entity\SavedTask;
use App\Entity\SavedTaskRun;
use App\Entity\User;
use App\Repository\ApprovalRepository;
use App\Repository\SavedTaskRepository;
use App\Repository\SavedTaskRunRepository;
use App\Repository\UserRepository;
use App\Service\Multitask\Execution\DagExecutor;
use App\Service\Multitask\Execution\NodeContextRehydrator;
use App\Service\Multitask\TaskPlanStore;
use App\Service\RateLimitService;
use App\Service\SavedTask\Graph\SavedTaskPlanFactory;
use App\Service\Tool\Exception\ToolNotRegisteredException;
use App\Service\Tool\ToolRegistry;
use Psr\Log\LoggerInterface;

final readonly class SavedTaskResumeService
{
    public function __construct(
        private SavedTaskRunRepository $runs,
        private SavedTaskRepository $tasks,
        private UserRepository $users,
        private ApprovalRepository $approvals,
        private SavedTaskPlanFactory $planFactory,
        private NodeContextRehydrator $rehydrator,
        private DagExecutor $dagExecutor,
        private TaskPlanStore $planStore,
        private RateLimitService $rateLimits,
        private ToolRegistry $registry,
        private LoggerInterface $logger,
    ) {
    }

    public function resume(int $runId, string $nodeId, int $approvalId): SavedTaskRun
    {
        $run = $this->runs->find($runId);
        if (!$run instanceof SavedTaskRun) {
            throw new SavedTaskNotFoundException();
        }
        if (SavedTaskRun::STATUS_WAITING_APPROVAL !== $run->getStatus()) {
            throw new SavedTaskNotWaitingException();
        }
        $task = $this->tasks->find($run->getSavedTaskId());
        if (!$task instanceof SavedTask) {
            throw new SavedTaskNotFoundException();
        }
        $user = $this->users->find($task->getOwnerId());
        if (!$user instanceof User) {
            throw new SavedTaskDisabledException('This account cannot run Saved Tasks');
        }
        $approval = $this->approvals->find($approvalId);
        if (!$approval instanceof Approval) {
            throw new SavedTaskNotFoundException();
        }

        $limit = $this->rateLimits->checkLimit($user, 'MESSAGES');
        if (empty($limit['allowed'])) {
            $run->markFailed('Your usage limit was reached, so this run was skipped.');
            $run->clearWaitingNode();
            $this->runs->save($run);

            return $run;
        }

        $descriptor = $this->registry->get($task->getOwnerId(), $approval->getTool());
        if (null === $descriptor) {
            $run->markFailed((new ToolNotRegisteredException($approval->getTool()))->getMessage());
            $run->clearWaitingNode();
            $this->runs->save($run);
            $approval->markFailed('tool_not_registered');
            $this->approvals->save($approval);

            throw new ToolNotRegisteredException($approval->getTool());
        }

        $plan = $this->planFactory->fromTask($task);
        $context = $this->rehydrator->fromRun($run, [
            'saved_task_run_id' => $runId,
            'allow_unattended' => $task->allowsUnattended(),
        ]);
        $assembled = $this->dagExecutor->resume($plan, $context, $nodeId, $approval->getArgs() ?? []);
        $messageId = $run->getMessageId();
        $statuses = [];
        foreach (is_array($assembled['node_statuses'] ?? null) ? $assembled['node_statuses'] : [] as $id => $status) {
            if (is_string($id) && is_string($status)) {
                $statuses[$id] = $status;
            }
        }
        if (null !== $messageId) {
            $this->planStore->persistWithStatuses($messageId, $plan, null, $statuses);
        }

        $waiting = $this->waitingNode($statuses);
        if (null !== $waiting) {
            $run->markWaitingApproval($waiting, $run->getMessageId(), $run->getPlanSnapshot());
            $this->runs->save($run);

            return $run;
        }

        if (!empty($assembled['all_failed'])) {
            $failed = 'A step failed after approval';
            $run->markFailed($failed, $run->getMessageId(), $run->getPlanSnapshot());
            $run->clearWaitingNode();
            $task->recordFailure();
            $this->runs->save($run);
            $this->tasks->save($task);
            $approval->markFailed($failed);
            $this->approvals->save($approval);

            return $run;
        }

        $run->markCompleted($run->getMessageId(), $run->getPlanSnapshot());
        $run->clearWaitingNode();
        $task->recordSuccess();
        $this->runs->save($run);
        $this->tasks->save($task);
        $approval->markExecuted('task_run:'.$runId);
        $this->approvals->save($approval);

        $this->logger->info('Saved task resumed after approval', [
            'run_id' => $runId,
            'node_id' => $nodeId,
            'approval_id' => $approvalId,
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
}
