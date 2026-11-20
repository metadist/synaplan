<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Entity\Approval;
use App\Repository\ApprovalRepository;
use App\Service\Run\RunReapPolicy;
use App\Service\Run\RunState;

/**
 * What the run reaper may not decide alone for a chat run. A run that waits
 * for an approval takes the decision once the row has one (an expired row
 * counts as declined), or ends as stopped when its owner stopped it. A run
 * whose unit was lost twice ends as interrupted. Every run it ends still
 * reaches its chat.
 */
final readonly class ChatRunReapPolicy implements RunReapPolicy
{
    /** A wait with no decision is declined before the checkpoint's own lifetime runs out. */
    public const MAX_WAIT_SECONDS = (ChatRunStore::ACTIVE_TTL_HOURS - 2) * 3600;

    public function __construct(
        private ChatRunStore $store,
        private ApprovalRepository $approvals,
        private ChatRunApprovals $runApprovals,
        private ChatRunResume $resume,
        private ChatRunStep $step,
        private ChatRunUnitHandler $handler,
    ) {
    }

    public function isWaiting(RunState $state): bool
    {
        return ChatRunStatus::Waiting === ChatRunUnitHandler::chat($state)->status;
    }

    public function reapWait(RunState $state, int $ageSeconds): bool
    {
        $state = ChatRunUnitHandler::chat($state);
        if ($this->store->runs()->cancelRequested($state->runId)) {
            return $this->stop($state);
        }
        $approvalId = $state->awaitedApproval();
        if (null === $approvalId) {
            return false;
        }
        $approval = $this->approvals->find($approvalId);
        $decision = $approval instanceof Approval ? $this->runApprovals->decision($approval) : ChatRunState::DECISION_REJECTED;
        if (null === $decision && $ageSeconds >= self::MAX_WAIT_SECONDS) {
            $decision = ChatRunState::DECISION_REJECTED;
            $this->runApprovals->expire($approvalId);
        }

        return null !== $decision && $this->resume->continue($state, $approvalId, $decision);
    }

    public function resumeUnit(RunState $state): string
    {
        return ChatRunStep::UNIT;
    }

    public function interrupt(RunState $fresh, int $attempt): bool
    {
        $fresh = ChatRunUnitHandler::chat($fresh);
        if (!$fresh->isTerminal()) {
            $this->step->interrupt($fresh);
        }

        return true;
    }

    public function closed(RunState $state): void
    {
        $this->handler->settle(ChatRunUnitHandler::chat($state));
    }

    private function stop(ChatRunState $state): bool
    {
        $saved = $this->store->runs()->underClaim($state, ChatRunUnitHandler::LEASE_MS, function ($fresh): bool {
            $this->step->stop(ChatRunUnitHandler::chat($fresh));

            return true;
        });
        if (null === $saved) {
            return false;
        }
        $this->closed($saved);

        return true;
    }
}
