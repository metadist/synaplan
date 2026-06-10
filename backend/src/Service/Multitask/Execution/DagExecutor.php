<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution;

use App\Service\Multitask\Execution\Parallel\MediaNodeDispatcher;
use App\Service\Multitask\Execution\Parallel\MediaNodeRequest;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Plan\TaskPlan;
use Psr\Log\LoggerInterface;

/**
 * DAG executor for multi-task plans.
 *
 * Sequential mode (default): runs nodes in topological order; a failed node
 * skips its dependents (failure isolation); per-node progress is emitted.
 *
 * Parallel mode (Sprint 4, gated by MULTITASK_PARALLEL_ENABLED): hybrid —
 * heavy media nodes (image/video/audio) are offloaded to concurrent subprocesses
 * via {@see MediaNodeDispatcher} while text/other nodes run inline (preserving
 * token streaming). Bounded by a concurrency cap; results are assembled in plan
 * order regardless of completion order (deterministic). Failure isolation and
 * the per-node SSE events are identical to sequential mode.
 *
 * The assembled response (text + files + per-node statuses) comes from
 * {@see ResultAssembler}.
 */
final readonly class DagExecutor
{
    public function __construct(
        private RunnerRegistry $registry,
        private ResultAssembler $assembler,
        private MediaNodeDispatcher $mediaDispatcher,
        private MultitaskRoutingConfig $config,
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

        if ($this->config->isParallelEnabled()) {
            $this->executeParallel($plan, $context, $progressCallback);
        } else {
            $this->executeSequential($plan, $context, $progressCallback);
        }

        return $this->assembler->assemble($plan, $context);
    }

    private function executeSequential(TaskPlan $plan, NodeContext $context, ?callable $progressCallback): void
    {
        foreach ($plan->topologicalOrder() as $node) {
            $blockedBy = $this->unmetDependency($plan, $context, $node->id);
            if (null !== $blockedBy) {
                $context->setResult($node->id, NodeResult::skipped("dependency '{$blockedBy}' did not complete"));
                $this->emitState($progressCallback, $node, 'skipped');
                continue;
            }

            $this->runNodeInline($context, $node, $progressCallback);
        }
    }

    /**
     * Hybrid parallel scheduler: media nodes offloaded concurrently (capped),
     * everything else inline. Progress is monotonic each iteration (settle a
     * skip, start a node, or collect an in-flight job), so the loop terminates.
     *
     * If the turn aborts mid-flight (most commonly the progress callback
     * throwing because the streaming client disconnected), every still-running
     * media job is cancelled before the exception propagates — otherwise the
     * subprocesses would keep generating (and billing) into the void.
     */
    private function executeParallel(TaskPlan $plan, NodeContext $context, ?callable $progressCallback): void
    {
        $order = $plan->topologicalOrder();
        $total = count($order);
        $cap = $this->config->maxParallel();
        $timeout = $this->config->nodeTimeoutSeconds();

        /** @var array<string, Parallel\MediaNodeJob> $inflight */
        $inflight = [];

        try {
            while (count($context->allResults()) < $total) {
                $progressed = false;

                // 1) Skip nodes whose dependency has settled unsuccessfully.
                foreach ($order as $node) {
                    if (null !== $context->getResult($node->id) || isset($inflight[$node->id])) {
                        continue;
                    }
                    $blocked = $this->settledFailedDependency($context, $node);
                    if (null !== $blocked) {
                        $context->setResult($node->id, NodeResult::skipped("dependency '{$blocked}' did not complete"));
                        $this->emitState($progressCallback, $node, 'skipped');
                        $progressed = true;
                    }
                }

                // 2) Start every ready node: media → dispatch (capped), else inline.
                foreach ($order as $node) {
                    if (null !== $context->getResult($node->id) || isset($inflight[$node->id])) {
                        continue;
                    }
                    if (!$this->dependenciesSatisfied($context, $node)) {
                        continue;
                    }

                    if ($this->isMediaKind($node)) {
                        if (count($inflight) >= $cap) {
                            continue; // wait for a slot
                        }
                        $inflight[$node->id] = $this->mediaDispatcher->dispatch($this->mediaRequest($node, $context));
                        $this->emitState($progressCallback, $node, 'running');
                        $progressed = true;
                    } else {
                        $this->runNodeInline($context, $node, $progressCallback);
                        $progressed = true;
                    }
                }

                // 3) Nothing else could start → collect one in-flight media job.
                if (!$progressed) {
                    if ([] === $inflight) {
                        break; // deadlock guard (shouldn't happen for a valid DAG)
                    }
                    $this->collectOne($order, $inflight, $context, $progressCallback, $timeout);
                }
            }

            // Drain any remaining in-flight jobs (when only media nodes are left).
            while ([] !== $inflight) {
                $this->collectOne($order, $inflight, $context, $progressCallback, $timeout);
            }
        } finally {
            foreach ($inflight as $nodeId => $job) {
                $job->cancel();
                $this->logger->info('DagExecutor: cancelled in-flight media node after abort', ['node' => $nodeId]);
            }
        }
    }

    /**
     * Run a node synchronously in the current process (text/other; and the media
     * fallback in sequential mode). Sets the result and emits files + state.
     */
    private function runNodeInline(NodeContext $context, TaskNode $node, ?callable $progressCallback): void
    {
        $runner = $this->registry->get($node->capability);
        if (null === $runner) {
            $context->setResult($node->id, NodeResult::failed("no runner for capability '{$node->capability->value}'"));
            $this->emitState($progressCallback, $node, 'failed');
            $this->logger->warning('DagExecutor: no runner for capability', [
                'node' => $node->id,
                'capability' => $node->capability->value,
            ]);

            return;
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
        $this->emitFilesFor($node, $result, $progressCallback);
        $this->emitState($progressCallback, $node, $result->isSuccessful() ? 'done' : 'failed');
    }

    /**
     * Wait for (and settle) the first in-flight node in topological order.
     *
     * @param list<TaskNode>                       $order
     * @param array<string, Parallel\MediaNodeJob> $inflight
     */
    private function collectOne(array $order, array &$inflight, NodeContext $context, ?callable $progressCallback, int $timeout): void
    {
        foreach ($order as $node) {
            if (!isset($inflight[$node->id])) {
                continue;
            }
            $result = $inflight[$node->id]->wait($timeout);
            unset($inflight[$node->id]);
            $context->setResult($node->id, $result);
            $this->emitFilesFor($node, $result, $progressCallback);
            $this->emitState($progressCallback, $node, $result->isSuccessful() ? 'done' : 'failed');

            return;
        }
    }

    private function mediaRequest(TaskNode $node, NodeContext $context): MediaNodeRequest
    {
        $inputs = $context->resolveInputs($node);
        $prompt = $this->stringInput($inputs['prompt'] ?? $inputs['text'] ?? null) ?? (string) $context->message->getText();
        $language = is_string($context->classification['language'] ?? null)
            ? $context->classification['language']
            : ($context->message->getLanguage() ?: 'en');

        return new MediaNodeRequest(
            nodeId: $node->id,
            capability: $node->capability->value,
            prompt: $prompt,
            userId: $context->userId,
            language: $language,
            params: $node->params,
            // The subprocess reloads the real message by id so attachments
            // (pic2pic reference images) survive the process boundary; thread
            // and options ride along for handler parity with the inline path.
            messageId: $context->message->getId(),
            thread: $this->normalizeThread($context->thread),
            options: $context->options,
        );
    }

    /**
     * Flatten the thread into JSON-serialisable `{role, content}` pairs for the
     * subprocess (Doctrine entities cannot cross the process boundary). Mirrors
     * the normalisation the queue path uses in MediaGenerationHandler.
     *
     * @param array<int, \App\Entity\Message|array{role: string, content: string}> $thread
     *
     * @return list<array{role: string, content: string}>
     */
    private function normalizeThread(array $thread): array
    {
        $out = [];
        foreach ($thread as $entry) {
            if ($entry instanceof \App\Entity\Message) {
                $out[] = [
                    'role' => 'IN' === $entry->getDirection() ? 'user' : 'assistant',
                    'content' => $entry->getText(),
                ];
                continue;
            }

            $out[] = ['role' => $entry['role'], 'content' => $entry['content']];
        }

        return $out;
    }

    private function isMediaKind(TaskNode $node): bool
    {
        return in_array($node->capability->uiKind(), ['image', 'video', 'audio'], true);
    }

    private function dependenciesSatisfied(NodeContext $context, TaskNode $node): bool
    {
        foreach ($node->dependsOn as $dep) {
            $r = $context->getResult($dep);
            if (null === $r || !$r->isSuccessful()) {
                return false;
            }
        }

        return true;
    }

    private function settledFailedDependency(NodeContext $context, TaskNode $node): ?string
    {
        foreach ($node->dependsOn as $dep) {
            $r = $context->getResult($dep);
            if (null !== $r && !$r->isSuccessful()) {
                return $dep;
            }
        }

        return null;
    }

    private function stringInput(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            $parts = array_filter($value, 'is_string');

            return [] === $parts ? null : implode("\n\n", $parts);
        }

        return null;
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

    private function emitFilesFor(TaskNode $node, NodeResult $result, ?callable $progressCallback): void
    {
        if (!$result->isSuccessful()) {
            return;
        }
        foreach ($result->files as $file) {
            if (isset($file['path'])) {
                $this->emitFile($progressCallback, $node->id, is_string($file['type'] ?? null) ? $file['type'] : 'file', (string) $file['path']);
            }
        }
    }

    /**
     * Emit a per-node state change as a `task_update` SSE event. The frontend
     * uses node_id + state + kind to drive each task card; labels are i18n'd
     * client-side.
     */
    private function emitState(?callable $callback, TaskNode $node, string $state): void
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
