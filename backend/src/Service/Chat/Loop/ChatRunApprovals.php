<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Entity\Approval;
use App\Repository\ApprovalRepository;
use App\Service\Run\RunIds;
use App\Service\Tool\ApprovalService;
use Psr\Clock\ClockInterface;

/**
 * The approvals a chat run asked for. A run only runs a write that its owner
 * approved for exactly this run and this tool, with the arguments the owner
 * saw on the card.
 */
final readonly class ChatRunApprovals
{
    public const PREFIX = 'chat_run:';

    public function __construct(
        private ApprovalRepository $approvals,
        private ClockInterface $clock,
    ) {
    }

    public static function requestedBy(string $runId): string
    {
        return self::PREFIX.$runId;
    }

    /** The run an approval belongs to, or null when it is not a chat run's. */
    public static function runOf(string $requestedBy): ?string
    {
        if (!str_starts_with($requestedBy, self::PREFIX)) {
            return null;
        }
        $runId = substr($requestedBy, \strlen(self::PREFIX));

        return 1 === preg_match(RunIds::PATTERN, $runId) ? $runId : null;
    }

    /**
     * The arguments to run with, or null when this approval does not allow
     * the call: another owner, another run, another tool, or not approved.
     *
     * @return array<string, mixed>|null
     */
    public function approvedArgs(int $approvalId, ChatRunState $state, string $tool): ?array
    {
        $approval = $this->approvals->find($approvalId);
        if (!$approval instanceof Approval
            || $approval->getOwnerId() !== $state->userId
            || Approval::STATUS_APPROVED !== $approval->getStatus()
            || $approval->getRequestedBy() !== self::requestedBy($state->runId)
            || $approval->getTool() !== $tool) {
            return null;
        }

        return ApprovalService::executionArgs($approval);
    }

    /**
     * This run ran the approved call already. A unit that was lost after the
     * call comes back to it: the action happened, only its result is gone.
     */
    public function alreadyRan(int $approvalId, ChatRunState $state): bool
    {
        $approval = $this->approvals->find($approvalId);

        return $approval instanceof Approval
            && $approval->getOwnerId() === $state->userId
            && $approval->getRequestedBy() === self::requestedBy($state->runId)
            && \in_array($approval->getStatus(), [Approval::STATUS_EXECUTED, Approval::STATUS_FAILED], true);
    }

    /**
     * The run no longer waits for this approval: take the open card away, so
     * nobody approves an action that will not run.
     */
    public function expire(int $approvalId): void
    {
        $this->approvals->expireIfPending($approvalId, $this->clock->now()->getTimestamp());
    }

    /** The run ended: expire the approval it still had open, if any. A run asks for one at a time. */
    public function expireOpen(string $runId): void
    {
        $approval = $this->approvals->findLatestForRequestedBy(self::requestedBy($runId));
        if ($approval instanceof Approval && Approval::STATUS_PENDING === $approval->getStatus() && null !== $approval->getId()) {
            $this->expire($approval->getId());
        }
    }

    public function executed(int $approvalId, bool $ok): void
    {
        $approval = $this->approvals->find($approvalId);
        if (!$approval instanceof Approval || Approval::STATUS_APPROVED !== $approval->getStatus()) {
            return;
        }
        $ok ? $approval->markExecuted('chat_run') : $approval->markFailed('chat_run_failed');
        $this->approvals->save($approval);
    }

    /** The decision on an approval as the run reads it, or null while it is still open. */
    public function decision(Approval $approval): ?string
    {
        return match ($approval->getStatus()) {
            Approval::STATUS_APPROVED => ChatRunState::DECISION_APPROVED,
            Approval::STATUS_REJECTED, Approval::STATUS_EXPIRED => ChatRunState::DECISION_REJECTED,
            default => null,
        };
    }
}
