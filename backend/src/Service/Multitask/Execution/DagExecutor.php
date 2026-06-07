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
        // Wire the per-node token-chunk sink so streaming runners can emit
        // task_chunk events tagged with the running node id.
        if (null !== $progressCallback) {
            $context->setChunkSink(static function (string $nodeId, string $chunk) use ($progressCallback): void {
                $progressCallback([
                    'status' => 'task_chunk',
                    'message' => '',
                    'metadata' => ['node_id' => $nodeId, 'chunk' => $chunk],
                    'timestamp' => time(),
                ]);
            });
        }

        foreach ($plan->topologicalOrder() as $node) {
            // Skip if a dependency did not complete successfully.
            $blockedBy = $this->unmetDependency($plan, $context, $node->id);
            if (null !== $blockedBy) {
                $context->setResult($node->id, NodeResult::skipped("dependency '{$blockedBy}' did not complete"));
                $this->emitState($progressCallback, $node, 'skipped');
                continue;
            }

            $runner = $this->registry->get($node->capability);
            if (null === $runner) {
                $context->setResult($node->id, NodeResult::failed("no runner for capability '{$node->capability->value}'"));
                $this->emitState($progressCallback, $node, 'failed');
                $this->logger->warning('DagExecutor: no runner for capability', [
                    'node' => $node->id,
                    'capability' => $node->capability->value,
                ]);
                continue;
            }

            $context->beginNode($node->id);
            $this->emitState($progressCallback, $node, 'running');

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

            // Surface produced files to their card before the state update.
            if ($result->isSuccessful()) {
                foreach ($result->files as $file) {
                    if (isset($file['path'])) {
                        $this->emitFile($progressCallback, $node->id, is_string($file['type'] ?? null) ? $file['type'] : 'file', (string) $file['path']);
                    }
                }
            }

            $this->emitState($progressCallback, $node, $result->isSuccessful() ? 'done' : 'failed');
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

    /**
     * Emit a per-node state change as a `task_update` SSE event. The frontend
     * uses node_id + state + kind to drive each task card; labels are i18n'd
     * client-side.
     */
    private function emitState(?callable $callback, \App\Service\Multitask\Plan\TaskNode $node, string $state): void
    {
        if (null === $callback) {
            return;
        }
        $callback([
            'status' => 'task_update',
            'message' => '',
            'metadata' => [
                'node_id' => $node->id,
                'capability' => $node->capability->value,
                'kind' => $node->capability->uiKind(),
                'state' => $state,
            ],
            'timestamp' => time(),
        ]);
    }

    private function emitFile(?callable $callback, string $nodeId, string $type, string $url): void
    {
        if (null === $callback) {
            return;
        }
        $callback([
            'status' => 'task_file',
            'message' => '',
            'metadata' => ['node_id' => $nodeId, 'type' => $type, 'url' => $url],
            'timestamp' => time(),
        ]);
    }
}
