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
        [$gated, $effect] = $this->consult($tool, $invocation);
        if (null !== $gated) {
            return $this->observe($gated, $started, $tool, $invocation, $effect, false, true);
        }
        if ($invocation->decideOnly) {
            return $this->observe(new ToolOutcome(ToolOutcomeKind::Done, ''), $started, $tool, $invocation, $effect, false, false);
        }

        $execution = $this->executorFor($tool)->execute($tool, $invocation);
        $outcome = new ToolOutcome(
            $execution->isError ? ToolOutcomeKind::Failed : ToolOutcomeKind::Done,
            ToolLimits::clamp($execution->text),
            $this->grantedApprovalId($invocation),
            $execution->document,
            0,
            null,
            $execution->details,
        );

        return $this->observe($outcome, $started, $tool, $invocation, $effect, true, true);
    }

    /**
     * The audit row for a tool that has finished outside the invoker
     * (file work runs in the sidecar after the gate). A policy-only
     * check does not call this.
     */
    public function recordRan(ToolDescriptor $tool, int $actorId, string $requestId, ToolOutcomeKind $kind, int $durationMs): void
    {
        $this->writeAudit($tool, $actorId, $requestId, $kind, $durationMs, null);
    }

    /**
     * @return array{0: ?ToolOutcome, 1: SideEffect}
     */
    private function consult(ToolDescriptor $tool, ToolInvocation $invocation): array
    {
        $gate = $this->gate;
        $request = $invocation->decision;
        if (null === $gate) {
            if (PolicyOutcome::Block->value === $request?->nodeApproval) {
                return [new ToolOutcome(ToolOutcomeKind::Refused, ToolExecutionGate::NODE_BLOCK_REFUSAL), $tool->sideEffect];
            }

            return [null, $tool->sideEffect];
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
        // A chat fetch has no saved-task run to resume. Opening an approval
        // would run the tool in the chat continuation and leave the step waiting.
        $canPause = ToolChannel::MCP_FETCH !== $invocation->caller->channel
            || !str_starts_with($requestedBy, 'chat:');

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
            null,
            $canPause,
        );
        $effect = $decision['descriptor']->sideEffect;
        if (PolicyOutcome::Block === $decision['outcome']) {
            $refusal = $decision['refusal'] ?? 'I cannot do that. An administrator has turned this off.';

            return [new ToolOutcome(ToolOutcomeKind::Refused, (string) $refusal), $effect];
        }
        $approval = $decision['approval'] ?? null;
        if (PolicyOutcome::Approve === $decision['outcome'] && $approval instanceof Approval) {
            return [new ToolOutcome(ToolOutcomeKind::NeedsApproval, '', (int) $approval->getId(), null, 0, $approval), $effect];
        }

        return [null, $effect];
    }

    private function observe(
        ToolOutcome $outcome,
        int $started,
        ToolDescriptor $tool,
        ToolInvocation $invocation,
        SideEffect $effect,
        bool $ran,
        bool $count,
    ): ToolOutcome {
        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
        $observed = $outcome->withDuration($durationMs);
        if ($count) {
            $this->metrics?->record($tool->source->value, $effect->value, $observed->kind->value);
            $this->timeline?->recordTool($tool->source->value, $effect->value, $observed->kind->value, $durationMs);
        }
        if ($ran) {
            $requestId = $invocation->caller->requestId;
            if (null !== $invocation->decision) {
                $requestId = $invocation->decision->requestedBy;
            }
            $this->writeAudit(
                $this->classified($tool, $effect),
                (int) ($invocation->caller->userId ?? 0),
                $requestId ?? '',
                $observed->kind,
                $observed->durationMs,
                $observed->approvalId,
            );
        }
        if ($ran && $invocation->recordUsage) {
            $this->recordUsage($tool, $invocation, $observed);
        }

        return $observed;
    }

    private function grantedApprovalId(ToolInvocation $invocation): ?int
    {
        $id = $invocation->caller->approvalId;

        return null !== $id && $id > 0 ? $id : null;
    }

    private function classified(ToolDescriptor $tool, SideEffect $effect): ToolDescriptor
    {
        if ($effect === $tool->sideEffect) {
            return $tool;
        }

        return new ToolDescriptor(
            $tool->name,
            $tool->title,
            $tool->description,
            $tool->inputSchema,
            $effect,
            $tool->source,
            $tool->ownerId,
            $tool->policyException,
            $tool->meta,
        );
    }

    private function writeAudit(
        ToolDescriptor $tool,
        int $actorId,
        string $requestId,
        ToolOutcomeKind $kind,
        int $durationMs,
        ?int $approvalId,
    ): void {
        if (null === $this->audit) {
            return;
        }
        if (SideEffect::Write !== $tool->sideEffect && SideEffect::Destructive !== $tool->sideEffect) {
            return;
        }
        if (ToolOutcomeKind::Done !== $kind && ToolOutcomeKind::Failed !== $kind) {
            return;
        }

        $subject = [
            'tool' => $tool->name,
            'source' => $tool->source->value,
            'kind' => $tool->sideEffect->value,
            'outcome' => $kind->value,
            'durationMs' => $durationMs,
        ];
        if ('' !== $requestId) {
            $subject['requestId'] = $requestId;
        }
        if (null !== $approvalId) {
            $subject['approvalId'] = $approvalId;
        }

        $this->audit->record($actorId, 'tool.executed', 'tool', $tool->name, $subject);
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
