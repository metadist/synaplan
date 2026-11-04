<?php

declare(strict_types=1);

namespace App\Service\Tool\Execution;

use App\Entity\Approval;
use App\Entity\User;
use App\Observability\RequestTimelineContext;
use App\Repository\UserRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\RateLimitService;
use App\Service\Tool\Policy\PolicyContext;
use App\Service\Tool\Policy\PolicyOutcome;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolExecutionGate;
use App\Service\Tool\ToolSource;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Registry, gate, executor, clamp, audit and the metrics row for one tool call.
 *
 * The gate runs on every call that has an actor. A write or a destructive
 * call that actually runs leaves one `tool.executed` row. The row never
 * contains the arguments or the result. A caller that already writes a
 * `TOOLS` usage row leaves {@see ToolInvocation::$recordUsage} off.
 */
final readonly class ToolInvoker
{
    /**
     * @param iterable<ToolExecutorInterface> $executors
     */
    public function __construct(
        #[AutowireIterator('app.tool.executor')]
        private iterable $executors,
        private ?ToolMetrics $metrics = null,
        private ?RequestTimelineContext $timeline = null,
        private ?ToolExecutionGate $gate = null,
        private ?AuditLogWriter $audit = null,
        private ?RateLimitService $rates = null,
        private ?UserRepository $users = null,
        private ?ToolUsageRecorder $usage = null,
    ) {
    }

    public function invoke(ToolDescriptor $tool, ToolInvocation $invocation): ToolOutcome
    {
        $started = hrtime(true);
        $gated = $this->consult($invocation);
        if (null !== $gated) {
            return $this->observe($gated, $started, $tool, $invocation, false, true);
        }
        if ($invocation->decideOnly) {
            return $this->observe(new ToolOutcome(ToolOutcomeKind::Done, ''), $started, $tool, $invocation, false, false);
        }

        $execution = $this->executorFor($tool)->execute($tool, $invocation);
        $outcome = new ToolOutcome(
            $execution->isError ? ToolOutcomeKind::Failed : ToolOutcomeKind::Done,
            ToolLimits::clamp($execution->text),
            null,
            $execution->document,
            0,
            null,
            $execution->details,
        );

        return $this->observe($outcome, $started, $tool, $invocation, true, true);
    }

    private function consult(ToolInvocation $invocation): ?ToolOutcome
    {
        $gate = $this->gate;
        $request = $invocation->decision;
        if (null === $gate) {
            if (PolicyOutcome::Block->value === $request?->nodeApproval) {
                return new ToolOutcome(ToolOutcomeKind::Refused, ToolExecutionGate::NODE_BLOCK_REFUSAL);
            }

            return null;
        }

        $actor = $this->actor($invocation);
        if (!$actor instanceof User) {
            throw new \LogicException(sprintf('Tool "%s" inspects the gate but has no actor.', $invocation->toolName));
        }
        $context = $invocation->caller->unattended ? PolicyContext::Unattended : PolicyContext::Interactive;
        $requestedBy = $invocation->caller->requestId ?? 'chat:0';
        $assistantTools = null;
        $allowUnattended = false;
        $assistantKey = null;
        $nodeApproval = null;
        $requireApproval = false;
        $resolvedRequest = null;
        $alreadyApproved = false;
        if (null !== $request) {
            $context = $request->context;
            $requestedBy = $request->requestedBy;
            $assistantTools = $request->assistantTools;
            $allowUnattended = $request->allowUnattended;
            $assistantKey = $request->assistantKey;
            $nodeApproval = $request->nodeApproval;
            $requireApproval = $request->requireApproval;
            $resolvedRequest = $request->resolvedRequest;
            $alreadyApproved = $request->alreadyApproved;
        }

        $decision = $gate->inspect(
            (int) $actor->getId(),
            $invocation->toolName,
            $invocation->arguments,
            $actor,
            $context,
            $requestedBy,
            $assistantTools,
            $allowUnattended,
            $assistantKey,
            $nodeApproval,
            $requireApproval,
            $resolvedRequest,
            $alreadyApproved,
        );
        if (PolicyOutcome::Block === $decision['outcome']) {
            $refusal = $decision['refusal'] ?? 'I cannot do that. An administrator has turned this off.';

            return new ToolOutcome(ToolOutcomeKind::Refused, (string) $refusal);
        }
        $approval = $decision['approval'] ?? null;
        if (PolicyOutcome::Approve === $decision['outcome'] && $approval instanceof Approval) {
            return new ToolOutcome(ToolOutcomeKind::NeedsApproval, '', (int) $approval->getId(), null, 0, $approval);
        }

        return null;
    }

    private function observe(
        ToolOutcome $outcome,
        int $started,
        ToolDescriptor $tool,
        ToolInvocation $invocation,
        bool $ran,
        bool $count,
    ): ToolOutcome {
        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
        $observed = $outcome->withDuration($durationMs);
        if ($count) {
            $this->metrics?->record($tool->source->value, $tool->sideEffect->value, $observed->kind->value);
            $this->timeline?->recordTool($tool->source->value, $tool->sideEffect->value, $observed->kind->value, $durationMs);
        }
        if ($ran || ($invocation->decideOnly && ToolOutcomeKind::Done === $observed->kind)) {
            $this->auditExecuted($tool, $invocation, $observed);
        }
        if ($ran && $invocation->recordUsage) {
            $this->recordUsage($tool, $invocation, $observed);
        }

        return $observed;
    }

    private function auditExecuted(ToolDescriptor $tool, ToolInvocation $invocation, ToolOutcome $outcome): void
    {
        if (null === $this->audit) {
            return;
        }
        if (SideEffect::Write !== $tool->sideEffect && SideEffect::Destructive !== $tool->sideEffect) {
            return;
        }
        if (ToolOutcomeKind::Done !== $outcome->kind && ToolOutcomeKind::Failed !== $outcome->kind) {
            return;
        }

        $requestId = $invocation->caller->requestId;
        if (null !== $invocation->decision) {
            $requestId = $invocation->decision->requestedBy;
        }
        $subject = [
            'tool' => $invocation->toolName,
            'source' => $tool->source->value,
            'kind' => $tool->sideEffect->value,
            'outcome' => $outcome->kind->value,
            'durationMs' => $outcome->durationMs,
        ];
        if (null !== $requestId && '' !== $requestId) {
            $subject['requestId'] = $requestId;
        }
        if (null !== $outcome->approvalId) {
            $subject['approvalId'] = $outcome->approvalId;
        }

        $this->audit->record(
            (int) ($invocation->caller->userId ?? 0),
            'tool.executed',
            'tool',
            $invocation->toolName,
            $subject,
        );
    }

    private function recordUsage(ToolDescriptor $tool, ToolInvocation $invocation, ToolOutcome $outcome): void
    {
        $user = $invocation->user ?? $this->actor($invocation);
        if (!$user instanceof User) {
            return;
        }
        $recorder = $this->usage ?? (null !== $this->rates ? new ToolUsageRecorder($this->rates) : null);
        if (null === $recorder) {
            return;
        }

        $details = $outcome->details;
        $mcpTool = is_string($tool->meta['tool'] ?? null) ? $tool->meta['tool'] : $tool->name;
        $error = ToolOutcomeKind::Failed === $outcome->kind;
        if (ToolSource::Mcp === $tool->source) {
            $recorder->record(
                $user,
                'MCP_TOOL',
                'mcp',
                sprintf('server:%d/%s', (int) ($tool->meta['serverId'] ?? 0), $mcpTool),
                is_string($details['usageInput'] ?? null) ? $details['usageInput'] : $mcpTool,
                $error,
            );

            return;
        }

        $source = is_string($details['usageSource'] ?? null) ? $details['usageSource'] : match ($tool->source) {
            ToolSource::Custom => 'CUSTOM_HTTP',
            ToolSource::Builtin => 'BUILTIN',
            ToolSource::Compute => 'COMPUTE_RUNS',
            default => strtoupper($tool->source->value),
        };
        $input = is_string($details['usageInput'] ?? null) ? $details['usageInput'] : $mcpTool;
        $recorder->record($user, $source, 'synaplan', 'tool:'.$mcpTool, $input, $error);
    }

    private function actor(ToolInvocation $invocation): ?User
    {
        $actor = $invocation->user;
        if (null !== $invocation->decision && $invocation->decision->actor instanceof User) {
            $actor = $invocation->decision->actor;
        }
        if ($actor instanceof User) {
            return $actor;
        }
        $id = (int) ($invocation->caller->userId ?? 0);
        if ($id < 1 || null === $this->users) {
            return null;
        }
        $found = $this->users->find($id);

        return $found instanceof User ? $found : null;
    }

    private function executorFor(ToolDescriptor $tool): ToolExecutorInterface
    {
        foreach ($this->executors as $executor) {
            if ($executor->supports($tool)) {
                return $executor;
            }
        }

        throw new \RuntimeException(sprintf('No tool executor supports "%s".', $tool->name));
    }
}
