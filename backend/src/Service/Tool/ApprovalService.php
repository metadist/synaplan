<?php

declare(strict_types=1);

namespace App\Service\Tool;

use App\Entity\Approval;
use App\Entity\User;
use App\Repository\ApprovalRepository;
use App\Repository\MessageRepository;
use App\Repository\SavedTaskRunRepository;
use App\Repository\UserRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\InternalEmailService;
use App\Service\MailerConfig;

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
        private ?ChatApprovalContinuationService $continuation = null,
        private ?MailerConfig $mailerConfig = null,
        private ?UserRepository $users = null,
    ) {
    }

    /**
     * Arguments the tool will actually receive. The stored row may also carry
     * the masked request shown on the card.
     *
     * @return array<string, mixed>
     */
    public static function executionArgs(Approval $approval): array
    {
        $args = $approval->getArgs() ?? [];
        unset($args['resolvedRequest']);

        return $args;
    }

    /**
     * @param array<string, mixed>      $args
     * @param array<string, mixed>|null $resolvedRequest method, url, masked headers, body
     */
    public function request(ToolDescriptor $tool, array $args, string $requestedBy, User $owner, ?array $resolvedRequest = null): Approval
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
        $maskedRequest = $this->maskResolvedRequest($resolvedRequest);
        if (null !== $maskedRequest) {
            $redacted['resolvedRequest'] = $maskedRequest;
        }
        $approval->setArgs($redacted);
        $approval->setPreview($this->redactor->preview($tool->title, $this->redactor->redact($args)));
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
        if ($alwaysAllow && $this->canAlwaysAllow($approval) && null !== $assistantKey && '' !== $assistantKey) {
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
        $announce = $this->continuation?->continueChat($approval, ChatApprovalContinuationService::OUTCOME_REJECTED, null);
        $this->approvals->save($approval);
        if (null !== $announce) {
            $announce();
        }
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
        if (null !== $this->mailerConfig && !$this->mailerConfig->isConfigured()) {
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
                $approval->getTool(),
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
            'decidedBy' => $approval->getDecidedBy(),
            'decidedByName' => $this->deciderName($approval),
            'resolvedRequest' => $this->resolvedRequest($approval),
            'requestedBy' => $reference->toArray(),
            'canAlwaysAllow' => $approval->isPending() && $this->canAlwaysAllow($approval),
        ];
    }

    /**
     * @param array<string, mixed>|null $request
     *
     * @return array{method: string, url: string, headers: array<string, string>, body: string|null}|null
     */
    private function maskResolvedRequest(?array $request): ?array
    {
        if (null === $request) {
            return null;
        }
        $method = strtoupper(trim((string) ($request['method'] ?? '')));
        $url = trim((string) ($request['url'] ?? ''));
        if ('' === $method || '' === $url) {
            return null;
        }
        $headers = [];
        $rawHeaders = is_array($request['headers'] ?? null) ? $request['headers'] : [];
        foreach ($rawHeaders as $name => $value) {
            $header = (string) $name;
            $text = is_scalar($value) ? (string) $value : '';
            if (1 === preg_match('/authorization|token|secret|password|api[_-]?key|credential/i', $header)) {
                $text = '[redacted]';
            }
            $headers[$header] = $text;
        }
        $body = isset($request['body']) && is_string($request['body']) ? $request['body'] : null;
        if (null !== $body && mb_strlen($body) > 8192) {
            $body = mb_substr($body, 0, 8192);
        }

        return [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];
    }

    /**
     * @return array{method: string, url: string, headers: array<string, string>, body: string|null}|null
     */
    private function resolvedRequest(Approval $approval): ?array
    {
        $stored = $approval->getArgs()['resolvedRequest'] ?? null;

        return is_array($stored) ? $this->maskResolvedRequest($stored) : null;
    }

    private function deciderName(Approval $approval): ?string
    {
        $id = $approval->getDecidedBy();
        if (null === $id || null === $this->users) {
            return null;
        }
        $user = $this->users->find($id);

        return $user instanceof User ? $user->getDisplayName() : null;
    }

    /**
     * "Always allow" only ever loosens approve → auto for write-class tools;
     * destructive calls must be confirmed one by one ({@see ApprovalPolicy}).
     */
    private function canAlwaysAllow(Approval $approval): bool
    {
        return SideEffect::Write->value === $approval->getSideEffect();
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
