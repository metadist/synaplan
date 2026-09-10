<?php

declare(strict_types=1);

namespace App\Service\Tool;

use App\Entity\Approval;
use App\Entity\SavedTask;
use App\Entity\SavedTaskRun;
use App\Entity\User;
use App\Repository\ApprovalRepository;
use App\Repository\SavedTaskRepository;
use App\Repository\SavedTaskRunRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;

final readonly class ApprovalExpiryService
{
    public function __construct(
        private ApprovalRepository $approvalRepository,
        private SavedTaskRunRepository $savedTaskRunRepository,
        private SavedTaskRepository $savedTaskRepository,
        private UserRepository $userRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function sweep(\DateTimeImmutable $now): int
    {
        $expired = $this->approvalRepository->findExpiredPending($now->getTimestamp());
        $count = 0;
        foreach ($expired as $approval) {
            $approval->markExpired();
            $this->failLinkedRun($approval);
            ++$count;
        }
        $this->approvalRepository->flush();
        if ($count > 0) {
            $this->logger->info('Expired pending approvals', ['count' => $count]);
        }

        return $count;
    }

    /**
     * @return list<array{user: User, approvals: list<Approval>}>
     */
    public function pendingForDigest(): array
    {
        $pending = $this->approvalRepository->findPending();
        $byUser = [];
        foreach ($pending as $approval) {
            $userId = $approval->getOwnerId();
            $byUser[$userId] ??= [];
            $byUser[$userId][] = $approval;
        }

        $out = [];
        foreach ($byUser as $userId => $approvals) {
            $user = $this->userRepository->find($userId);
            if ($user instanceof User) {
                $out[] = ['user' => $user, 'approvals' => $approvals];
            }
        }

        return $out;
    }

    private function failLinkedRun(Approval $approval): void
    {
        $reference = ApprovalReference::parse($approval->getRequestedBy());
        if (ApprovalReference::KIND_TASK_RUN !== $reference->kind || null === $reference->runId) {
            return;
        }
        $run = $this->savedTaskRunRepository->find($reference->runId);
        if (!$run instanceof SavedTaskRun || SavedTaskRun::STATUS_WAITING_APPROVAL !== $run->getStatus()) {
            return;
        }
        $run->markFailed('Nobody approved in time');
        $run->clearWaitingNode();
        $this->savedTaskRunRepository->save($run);

        $task = $this->savedTaskRepository->find($run->getSavedTaskId());
        if ($task instanceof SavedTask) {
            $task->recordFailure();
            $this->savedTaskRepository->save($task);
        }
    }
}
