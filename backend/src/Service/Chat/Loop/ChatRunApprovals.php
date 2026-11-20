<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Entity\Approval;
use App\Repository\ApprovalRepository;
use App\Service\Run\RunIds;
use App\Service\Tool\ApprovalService;

/**
 * The approvals a chat run asked for. A run only runs a write that its owner
 * approved for exactly this run and this tool, with the arguments the owner
 * saw on the card.
 */
final readonly class ChatRunApprovals
{
    public const PREFIX = 'chat_run:';

    public function __construct(private ApprovalRepository $approvals)
    {
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
