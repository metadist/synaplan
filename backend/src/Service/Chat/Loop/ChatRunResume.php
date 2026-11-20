<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Entity\Approval;
use App\Repository\ApprovalRepository;
use App\Service\Run\RunNotFound;

/**
 * Carries the owner's decision on an approval into the chat run that waits
 * for it. The decision is read from the approval row, never from the
 * request, and only the run that asked for this approval takes it.
 */
final readonly class ChatRunResume
{
    public function __construct(
        private ApprovalRepository $approvals,
        private ChatRunApprovals $runApprovals,
        private ChatRunStore $store,
        private ChatRunUnitHandler $handler,
        private MessengerChatRunUnitBus $bus,
        private ChatRunEvents $events,
    ) {
    }

    public function decided(int $approvalId): void
    {
        $approval = $this->approvals->find($approvalId);
        if (!$approval instanceof Approval) {
            return;
        }
        $runId = ChatRunApprovals::runOf($approval->getRequestedBy());
        $decision = $this->runApprovals->decision($approval);
        if (null === $runId || null === $decision) {
            return;
        }
        $state = $this->store->load($runId);
        if (null === $state || $state->userId !== $approval->getOwnerId()) {
            return;
        }
        $this->continue($state, $approvalId, $decision);
    }

    /**
     * Records the decision under the claim and queues the next unit. False
     * when the run no longer waits for this approval.
     */
    public function continue(ChatRunState $state, int $approvalId, string $decision): bool
    {
        $saved = $this->store->runs()->underClaim($state, ChatRunUnitHandler::LEASE_MS, function ($fresh) use ($approvalId, $decision): bool {
            $fresh = ChatRunUnitHandler::chat($fresh);
            if (ChatRunStatus::Waiting !== $fresh->status || !$fresh->decide($approvalId, $decision)) {
                return false;
            }
            $fresh->resume();
            try {
                $this->events->append($fresh->runId, ChatRunEvents::RESUMED, ['decision' => $decision]);
            } catch (RunNotFound) {
                return false;
            }

            return true;
        });
        if (null === $saved) {
            return false;
        }
        $saved = ChatRunUnitHandler::chat($saved);
        $this->handler->settle($saved);
        $this->bus->dispatch($saved->runId, ChatRunStep::UNIT, $saved->expectedSeq(), 0);

        return true;
    }
}
