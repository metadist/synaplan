<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution;

use App\Service\Multitask\Plan\TaskPlan;
use Psr\Log\LoggerInterface;

/**
 * Sequential DAG executor (Sprint 3).
 *
 * Runs a plan's nodes in topological order. Each node:
 *   - is skipped (NodeStatus::Skipped) if any dependency did not finish Done —
 *     failure isolation: a broken branch never poisons an independent branch;
 *   - otherwise runs via the capability's {@see TaskRunner}, guarded by
 *     try/catch as a backstop so a runner that throws can't crash the turn;
 *   - emits a per-node progress status (for SSE "Extracting… Summarising…").
 *
 * The assembled response (text + files + per-node statuses) is returned via
 * {@see ResultAssembler}. Parallelism of independent nodes is Sprint 4; this
 * executor is purely sequential.
 */
final readonly class DagExecutor
{
    public function __construct(
        private RunnerRegistry $registry,
        private ResultAssembler $assembler,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{
     *     content: string,
     *     files: list<array<string, mixed>>,
     *     metadata: array<string, mixed>,
     *     node_statuses: array<string, string>,
     *     partial_failure: bool,
     *     all_failed: bool
     * }
     */
    public function execute(TaskPlan $plan, NodeContext $context, ?callable $progressCallback = null): array
    {
        foreach ($plan->topologicalOrder() as $node) {
            // Skip if a dependency did not complete successfully.
            $blockedBy = $this->unmetDependency($plan, $context, $node->id);
            if (null !== $blockedBy) {
                $context->setResult($node->id, NodeResult::skipped("dependency '{$blockedBy}' did not complete"));
                $this->notify($progressCallback, 'task_skipped', $node->id, $node->capability->value);
                continue;
            }

            $runner = $this->registry->get($node->capability);
            if (null === $runner) {
                $context->setResult($node->id, NodeResult::failed("no runner for capability '{$node->capability->value}'"));
                $this->logger->warning('DagExecutor: no runner for capability', [
                    'node' => $node->id,
                    'capability' => $node->capability->value,
                ]);
                continue;
            }

            $this->notify($progressCallback, 'task_running', $node->id, $node->capability->value);

            try {
                $result = $runner->run($node, $context);
            } catch (\Throwable $e) {
                $result = NodeResult::failed($e->getMessage());
                $this->logger->warning('DagExecutor: runner threw', [
                    'node' => $node->id,
                    'capability' => $node->capability->value,
                    'error' => $e->getMessage(),
                ]);
            }

            $context->setResult($node->id, $result);
            $this->notify(
                $progressCallback,
                $result->isSuccessful() ? 'task_done' : 'task_failed',
                $node->id,
                $node->capability->value,
            );
        }

        return $this->assembler->assemble($plan, $context);
    }

    /**
     * First dependency id of $nodeId that did not finish Done, or null if all met.
     */
    private function unmetDependency(TaskPlan $plan, NodeContext $context, string $nodeId): ?string
    {
        $node = $plan->nodeById($nodeId);
        if (null === $node) {
            return null;
        }
        foreach ($node->dependsOn as $dep) {
            $depResult = $context->getResult($dep);
            if (null === $depResult || !$depResult->isSuccessful()) {
                return $dep;
            }
        }

        return null;
    }

    private function notify(?callable $callback, string $status, string $nodeId, string $capability): void
    {
        if (null === $callback) {
            return;
        }
        $callback([
            'status' => $status,
            'message' => $this->humanLabel($status, $capability),
            'metadata' => ['node_id' => $nodeId, 'capability' => $capability],
            'timestamp' => time(),
        ]);
    }

    private function humanLabel(string $status, string $capability): string
    {
        $verb = match ($capability) {
            'extract_text' => 'Extracting text',
            'summarize' => 'Summarising',
            'translate' => 'Translating',
            'chat', 'rag_query' => 'Thinking',
            'web_search' => 'Searching the web',
            'file_analysis' => 'Analysing file',
            'image_generation' => 'Generating image',
            'video_generation' => 'Generating video',
            'text2sound' => 'Generating audio',
            'document_generation' => 'Generating document',
            'compose_reply' => 'Composing reply',
            default => 'Working',
        };

        return match ($status) {
            'task_running' => $verb.'…',
            'task_done' => $verb.' — done',
            'task_failed' => $verb.' — failed',
            'task_skipped' => $verb.' — skipped',
            default => $verb,
        };
    }
}
