<?php

declare(strict_types=1);

namespace App\Service\Tool\Execution;

use App\Observability\RequestTimelineContext;
use App\Service\Tool\ToolDescriptor;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Registry, gate, executor, clamp, and the metrics row for one tool call.
 *
 * AU24 keeps each caller's current gate decision via {@see GateMode}. The
 * audit row and the usage row stay off unless the caller already wrote
 * them; AU25 turns both on.
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
    ) {
    }

    public function invoke(ToolDescriptor $tool, ToolInvocation $invocation): ToolOutcome
    {
        $started = hrtime(true);
        if (GateMode::AsTodayNever !== $invocation->gate) {
            throw new \LogicException('Only the document loop is on the invoker. Other gate modes land with their callers.');
        }
        $execution = $this->executorFor($tool)->execute($tool, $invocation);
        $text = ToolLimits::clamp($execution->text);
        $kind = $execution->isError ? ToolOutcomeKind::Failed : ToolOutcomeKind::Done;
        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
        $this->metrics?->record($execution->source, $execution->kind, $kind->value);
        $this->timeline?->recordTool($execution->source, $execution->kind, $kind->value, $durationMs);

        return new ToolOutcome($kind, $text, null, $execution->document, $durationMs);
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
