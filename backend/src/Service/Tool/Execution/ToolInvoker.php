<?php

declare(strict_types=1);

namespace App\Service\Tool\Execution;

use App\Entity\Approval;
use App\Entity\User;
use App\Observability\RequestTimelineContext;
use App\Service\Tool\Policy\PolicyOutcome;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolExecutionGate;
use App\Service\Tool\ToolsConfig;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Registry, gate, executor, clamp, and the metrics row for one tool call.
 *
 * AU24 keeps each caller's current gate decision via {@see GateMode}.
 * A caller that still owns the side effect (file work) passes
 * {@see ToolInvocation::$decideOnly} so the gate runs and the executor does not.
 * The audit row stays off; AU25 turns it on. Usage stays with the caller
 * that already wrote a row.
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
        private ?ToolsConfig $toolsConfig = null,
    ) {
    }

    public function invoke(ToolDescriptor $tool, ToolInvocation $invocation): ToolOutcome
    {
        $started = hrtime(true);
        $gated = $this->consult($invocation);
        if (null !== $gated) {
            return $this->observe($gated, $started, $tool->source->value, $tool->sideEffect->value);
        }
        if ($invocation->decideOnly) {
            return $this->observe(new ToolOutcome(ToolOutcomeKind::Done, ''), $started, $tool->source->value, $tool->sideEffect->value, false);
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

        return $this->observe($outcome, $started, $execution->source, $execution->kind);
    }

    private function consult(ToolInvocation $invocation): ?ToolOutcome
    {
        if (true === $invocation->decision?->skip) {
            return null;
        }

        $gate = $this->gate;
        $userId = (int) ($invocation->caller->userId ?? 0);
        $inspect = match ($invocation->gate) {
            GateMode::AsTodayNever => false,
            GateMode::AsTodayWhenApprovalsOn => null !== $gate && $this->approvalsOn($userId),
            GateMode::AsTodayAlways => null !== $gate,
        };
        if (!$inspect || null === $gate) {
            if (
                GateMode::AsTodayAlways === $invocation->gate
                && null === $gate
                && PolicyOutcome::Block->value === $invocation->decision?->nodeApproval
            ) {
                return new ToolOutcome(ToolOutcomeKind::Refused, ToolExecutionGate::NODE_BLOCK_REFUSAL);
            }

            return null;
        }

        $request = $invocation->decision;
        if (null === $request) {
            throw new \LogicException(sprintf('Tool "%s" inspects the gate but has no gate request.', $invocation->toolName));
        }

        if (!$request->actor instanceof User) {
            throw new \LogicException(sprintf('Tool "%s" inspects the gate but has no actor.', $invocation->toolName));
        }

        $decision = $gate->inspect(
            $userId,
            $invocation->toolName,
            $invocation->arguments,
            $request->actor,
            $request->context,
            $request->requestedBy,
            $request->assistantTools,
            $request->allowUnattended,
            $request->assistantKey,
            $request->nodeApproval,
            $request->requireApproval,
            $request->resolvedRequest,
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

    private function observe(ToolOutcome $outcome, int $started, string $source, string $kind, bool $record = true): ToolOutcome
    {
        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
        $observed = $outcome->withDuration($durationMs);
        if ($record) {
            $this->metrics?->record($source, $kind, $observed->kind->value);
            $this->timeline?->recordTool($source, $kind, $observed->kind->value, $durationMs);
        }

        return $observed;
    }

    private function approvalsOn(int $userId): bool
    {
        return null !== $this->toolsConfig && $this->toolsConfig->isApprovalsEnabled($userId);
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
