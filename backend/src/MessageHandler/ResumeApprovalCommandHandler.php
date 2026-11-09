<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Approval;
use App\Message\ResumeApprovalCommand;
use App\Message\ResumeSavedTaskRunCommand;
use App\Repository\ApprovalRepository;
use App\Repository\CustomToolRepository;
use App\Repository\McpServerConfigRepository;
use App\Repository\UserRepository;
use App\Service\Mcp\McpClient;
use App\Service\Tool\ApprovalRealtimeNotifier;
use App\Service\Tool\ApprovalReference;
use App\Service\Tool\ApprovalService;
use App\Service\Tool\ChatApprovalContinuationService;
use App\Service\Tool\Custom\HttpToolExecutor;
use App\Service\Tool\Execution\CustomHttpToolExecutor;
use App\Service\Tool\Execution\GateRequest;
use App\Service\Tool\Execution\McpToolExecutor;
use App\Service\Tool\Execution\ToolCaller;
use App\Service\Tool\Execution\ToolChannel;
use App\Service\Tool\Execution\ToolInvocation;
use App\Service\Tool\Execution\ToolInvoker;
use App\Service\Tool\Execution\ToolInvokerSlot;
use App\Service\Tool\Execution\ToolOutcome;
use App\Service\Tool\Execution\ToolOutcomeKind;
use App\Service\Tool\Policy\PolicyContext;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolRegistry;
use App\Service\Tool\ToolSource;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Runs an approved tool call under the owner's identity.
 *
 * Saved Task runs are handed to {@see ResumeSavedTaskRunCommandHandler}, which
 * re-executes the waiting node inside the DAG. Interactive requests are
 * executed here directly (custom HTTP and MCP tools); the outcome is written to
 * BRESULTREF and published on the owner's realtime channel so the inbox and the
 * chat card can show it. Chat approvals additionally continue the thread with
 * a one-sentence follow-up ({@see ChatApprovalContinuationService}, Q1).
 * Every failure marks the row `failed` — an approved row must never stay
 * `approved` forever, and a chat row must never claim `executed` for a tool
 * this handler cannot run.
 */
#[AsMessageHandler]
final readonly class ResumeApprovalCommandHandler
{
    private const RESULT_PREVIEW_CHARS = 2000;

    private ToolInvokerSlot $toolInvoker;

    public function __construct(
        private ApprovalRepository $approvals,
        private ToolRegistry $registry,
        private UserRepository $users,
        private CustomToolRepository $customTools,
        private HttpToolExecutor $httpExecutor,
        private McpServerConfigRepository $mcpServers,
        private McpClient $mcpClient,
        private ApprovalRealtimeNotifier $realtime,
        private ChatApprovalContinuationService $continuation,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
        $this->toolInvoker = new ToolInvokerSlot();
    }

    #[Required]
    public function setToolInvoker(ToolInvoker $toolInvoker): void
    {
        $this->toolInvoker->set($toolInvoker);
    }

    public function __invoke(ResumeApprovalCommand $command): void
    {
        $approval = $this->approvals->find($command->approvalId);
        if (!$approval instanceof Approval || Approval::STATUS_APPROVED !== $approval->getStatus()) {
            return;
        }

        $reference = ApprovalReference::parse($approval->getRequestedBy());
        if (ApprovalReference::KIND_TASK_RUN === $reference->kind && null !== $reference->runId && null !== $reference->nodeId) {
            $this->bus->dispatch(new ResumeSavedTaskRunCommand($reference->runId, $reference->nodeId, (int) $approval->getId()));

            return;
        }

        if (null === $this->users->find($approval->getOwnerId())) {
            $this->fail($approval, 'owner_missing', null);

            return;
        }

        $descriptor = $this->registry->get($approval->getOwnerId(), $approval->getTool());
        if (null === $descriptor) {
            $this->logger->warning('ResumeApproval: tool left the registry', ['tool' => $approval->getTool()]);
            $this->fail($approval, 'tool_not_registered', null);

            return;
        }

        if (ToolSource::Custom !== $descriptor->source && ToolSource::Mcp !== $descriptor->source) {
            $this->failUnsupported($approval);

            return;
        }

        try {
            [$resultRef, $summary] = ToolSource::Custom === $descriptor->source
                ? $this->executeCustom($approval, $descriptor)
                : $this->executeMcp($approval, $descriptor);
        } catch (\Throwable $e) {
            $this->logger->warning('ResumeApproval: execution failed', [
                'approval_id' => $approval->getId(),
                'tool' => $approval->getTool(),
                'error' => $e->getMessage(),
            ]);
            $this->fail($approval, 'execution_failed', $e->getMessage());

            return;
        }

        $announce = $this->continuation->continueChat($approval, ChatApprovalContinuationService::OUTCOME_EXECUTED, $summary);
        $approval->markExecuted($resultRef);
        $this->approvals->save($approval);
        $this->realtime->executed($approval, $summary);
        if (null !== $announce) {
            $announce();
        }
    }

    /**
     * Sources this handler cannot execute (builtin, skill, document, plugin,
     * compute). Outside chat the historic outcome is kept; in chat an approval
     * must never claim a run that never happened (U8), so the row fails
     * honestly and the thread gets the one-sentence follow-up (Q1).
     */
    private function failUnsupported(Approval $approval): void
    {
        $reference = ApprovalReference::parse($approval->getRequestedBy());
        if (ApprovalReference::KIND_CHAT !== $reference->kind) {
            $approval->markExecuted('chat');
            $this->approvals->save($approval);
            $this->realtime->executed($approval, null);

            return;
        }
        $announce = $this->continuation->continueChat($approval, ChatApprovalContinuationService::OUTCOME_UNSUPPORTED, null);
        $approval->markFailed('chat_execution_unsupported');
        $this->approvals->save($approval);
        $this->realtime->executed($approval, null);
        if (null !== $announce) {
            $announce();
        }
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function executeCustom(Approval $approval, ToolDescriptor $descriptor): array
    {
        return $this->finish($this->invoke($approval, $descriptor));
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function executeMcp(Approval $approval, ToolDescriptor $descriptor): array
    {
        return $this->finish($this->invoke($approval, $descriptor));
    }

    private function invoke(Approval $approval, ToolDescriptor $descriptor): ToolOutcome
    {
        return $this->tools()->invoke(
            $descriptor,
            new ToolInvocation(
                $approval->getTool(),
                ApprovalService::executionArgs($approval),
                new ToolCaller($approval->getOwnerId(), ToolChannel::RESUME, approvalId: (int) $approval->getId()),
                decision: new GateRequest(null, PolicyContext::Interactive, $approval->getRequestedBy(), alreadyApproved: true),
            ),
        );
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function finish(ToolOutcome $outcome): array
    {
        if (ToolOutcomeKind::Failed === $outcome->kind) {
            throw new \RuntimeException($outcome->resultText);
        }
        $ref = $outcome->details['ref'] ?? '';
        $summary = $outcome->details['summary'] ?? '';

        return [
            is_string($ref) ? $ref : '',
            $this->clip(is_string($summary) ? $summary : ''),
        ];
    }

    private function tools(): ToolInvoker
    {
        return $this->toolInvoker->get() ?? new ToolInvoker([
            new McpToolExecutor($this->mcpClient, $this->mcpServers),
            new CustomHttpToolExecutor($this->logger, $this->customTools, $this->httpExecutor),
        ]);
    }

    private function fail(Approval $approval, string $reason, ?string $detail): void
    {
        $clipped = null === $detail ? null : $this->clip($detail);
        $announce = $this->continuation->continueChat($approval, ChatApprovalContinuationService::OUTCOME_FAILED, $clipped);
        $approval->markFailed($reason);
        $this->approvals->save($approval);
        $this->realtime->executed($approval, $clipped);
        if (null !== $announce) {
            $announce();
        }
    }

    private function clip(string $text): ?string
    {
        $text = trim($text);
        if ('' === $text) {
            return null;
        }

        return mb_strlen($text) > self::RESULT_PREVIEW_CHARS
            ? mb_substr($text, 0, self::RESULT_PREVIEW_CHARS).'…'
            : $text;
    }
}
