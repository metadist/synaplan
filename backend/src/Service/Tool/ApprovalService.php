<?php

declare(strict_types=1);

namespace App\Service\Tool;

use App\Entity\Approval;
use App\Entity\User;
use App\Repository\ApprovalRepository;
use App\Repository\MessageRepository;
use App\Repository\SavedTaskRunRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\InternalEmailService;

final readonly class ApprovalService
{
    public function __construct(
        private ApprovalRepository $approvals,
        private ApprovalArgsRedactor $redactor,
        private ToolsConfig $toolsConfig,
        private ApprovalRealtimeNotifier $realtime,
        private AuditLogWriter $auditLogWriter,
        private ?MessageRepository $messages = null,
        private ?SavedTaskRunRepository $savedTaskRuns = null,
        private ?InternalEmailService $mail = null,
    ) {
    }

    /**
     * @param array<string, mixed> $args
     */
    public function request(ToolDescriptor $tool, array $args, string $requestedBy, User $owner): Approval
    {
        $expiresAt = time() + ($this->toolsConfig->approvalExpiryHours((int) $owner->getId()) * 3600);
        $approval = new Approval(
            (int) $owner->getId(),
            $requestedBy,
            $tool->name,
            $tool->sideEffect->value,
            $expiresAt,
        );
        $redacted = $this->redactor->redact($args);
        $approval->setArgs($redacted);
        $approval->setPreview($this->redactor->preview($tool->title, $redacted));
        $this->approvals->save($approval);
        $this->realtime->pending($approval);
        $this->auditLogWriter->record(
            (int) $owner->getId(),
            'approval.requested',
            'approval',
            (string) $approval->getId(),
            ['tool' => $tool->name, 'sideEffect' => $tool->sideEffect->value],
        );
        $this->notifyInstant($owner, $approval);

        return $approval;
    }

    public function approve(int $id, User $actor, bool $alwaysAllow = false, ?string $assistantKey = null): Approval
    {
        $approval = $this->requireOwnedPending($id, $actor);
        $approval->markApproved((int) $actor->getId());
        $this->approvals->save($approval);
        if ($alwaysAllow && null !== $assistantKey && '' !== $assistantKey) {
            $this->toolsConfig->addAlwaysAllow((int) $actor->getId(), $assistantKey, $approval->getTool());
        }
        $this->auditLogWriter->record(
            (int) $actor->getId(),
            'approval.approved',
            'approval',
            (string) $approval->getId(),
            ['tool' => $approval->getTool()],
        );
        $this->realtime->decided($approval);

        return $approval;
    }

    public function reject(int $id, User $actor, ?string $reason = null): Approval
    {
        $approval = $this->requireOwnedPending($id, $actor);
        $approval->markRejected((int) $actor->getId());
        $this->approvals->save($approval);
        $this->auditLogWriter->record(
            (int) $actor->getId(),
            'approval.rejected',
            'approval',
            (string) $approval->getId(),
            ['tool' => $approval->getTool(), 'reason' => $reason],
        );
        $this->realtime->decided($approval);

        return $approval;
    }

    private function notifyInstant(User $owner, Approval $approval): void
    {
        if (null === $this->mail) {
            return;
        }
        if (ToolsConfig::NOTIFY_INSTANT !== $this->toolsConfig->notifyMode((int) $owner->getId())) {
            return;
        }
        $address = trim($owner->getMail());
        if ('' === $address || str_ends_with(strtolower($address), '@synaplan.local')) {
            return;
        }
        $frontendUrl = $_ENV['FRONTEND_URL'] ?? $_ENV['APP_URL'] ?? 'http://localhost:5173';
        try {
            $this->mail->sendApprovalRequestEmail(
                $address,
                (string) $approval->getPreview(),
                rtrim($frontendUrl, '/').'/channels/approvals',
            );
        } catch (\Throwable) {
            // Mail is best-effort; the inbox row already exists.
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFor(User $user, string $status): array
    {
        $group = 'pending' === $status ? 'pending' : 'decided';
        $rows = [];
        foreach ($this->approvals->findForOwnerByStatus((int) $user->getId(), $group) as $approval) {
            $rows[] = $this->toArray($approval);
        }

        return $rows;
    }

    public function pendingCount(User $user): int
    {
        return $this->approvals->countPendingForOwner((int) $user->getId());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Approval $approval): array
    {
        $reference = $this->hydrateReference(ApprovalReference::parse($approval->getRequestedBy()));

        return [
            'id' => $approval->getId(),
            'tool' => $approval->getTool(),
            'sideEffect' => $approval->getSideEffect(),
            'preview' => $approval->getPreview(),
            'status' => $approval->getStatus(),
            'expiresAt' => $approval->getExpiresAt(),
            'created' => $approval->getCreated(),
            'decidedAt' => $approval->getDecidedAt(),
            'requestedBy' => $reference->toArray(),
            'canAlwaysAllow' => $approval->isPending() && 'write' === $approval->getSideEffect(),
        ];
    }

    private function hydrateReference(ApprovalReference $reference): ApprovalReference
    {
        if (ApprovalReference::KIND_CHAT === $reference->kind && null !== $reference->messageId && null !== $this->messages) {
            $message = $this->messages->find($reference->messageId);
            if (null !== $message && method_exists($message, 'getChatId')) {
                return $reference->withChatId((int) $message->getChatId());
            }
        }
        if (ApprovalReference::KIND_TASK_RUN === $reference->kind && null !== $reference->runId && null !== $this->savedTaskRuns) {
            $run = $this->savedTaskRuns->find($reference->runId);
            if (null !== $run) {
                return $reference->withTaskId($run->getSavedTaskId());
            }
        }

        return $reference;
    }

    private function requireOwnedPending(int $id, User $actor): Approval
    {
        $approval = $this->approvals->findOneForOwner($id, (int) $actor->getId());
        if (null === $approval || !$approval->isPending()) {
            throw new ApprovalNotFoundException($id);
        }

        return $approval;
    }
}
