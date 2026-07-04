<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution;

use App\Service\Multitask\Execution\Parallel\MediaNodeDispatcher;
use App\Service\Multitask\Execution\Parallel\MediaNodeRequest;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Multitask\Plan\Capability;
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
    /** Cap for the error string forwarded to the client on a failed/skipped node. */
    private const MAX_ERROR_LENGTH = 240;

    /** Cap for the resolved media prompt forwarded on a failed media node (retry payload). */
    private const MAX_PROMPT_LENGTH = 4000;

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
     *     node_job_keys: array<string, string>,
     *     partial_failure: bool,
     *     all_failed: bool
     * }
     */
    public function execute(TaskPlan $plan, NodeContext $context, ?callable $progressCallback = null): array
    {
        // A media node whose file is CONSUMED by a downstream processing node
        // (e.g. `file_analysis` reading `$n1.file` to describe the image) must
        // render synchronously — the bytes have to exist in-turn or that
        // dependent is blocked forever. Detaching it to an async job left the
        // dependent stuck, which (before #1218) was masked by the all_failed
        // legacy fallback re-generating the media. A media node attached ONLY to
        // `compose_reply` (pure delivery) keeps the async detach: compose_reply
        // waits and the background job delivers the file when it completes.
        $context->setInlineMediaNodeIds($this->mediaNodesConsumedByProcessingNodes($plan));

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

            // Live media progress (e.g. video render status) → task_progress, so
            // the card shows a moving bar instead of a static spinner.
            $context->setProgressSink(static function (string $nodeId, array $progress) use ($progressCallback): void {
                $progressCallback([
                    'status' => 'task_progress',
                    'message' => '',
                    'metadata' => array_merge(['node_id' => $nodeId], $progress),
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
            if (null !== $context->getResult($node->id)) {
                continue;
            }

            $failedDep = $this->failedDependency($context, $node);
            if (null !== $failedDep) {
                $result = NodeResult::skipped("dependency '{$failedDep}' did not complete");
                $context->setResult($node->id, $result);
                $this->emitState($progressCallback, $node, 'skipped', $this->failureMetadata($node, $result, $context));
                continue;
            }

            if ($this->blockedByIncompleteDependency($context, $node)) {
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
                        $result = NodeResult::skipped("dependency '{$blocked}' did not complete");
                        $context->setResult($node->id, $result);
                        $this->emitState($progressCallback, $node, 'skipped', $this->failureMetadata($node, $result, $context));
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
            $result = NodeResult::failed("no runner for capability '{$node->capability->value}'");
            $context->setResult($node->id, $result);
            $this->emitState($progressCallback, $node, 'failed', $this->failureMetadata($node, $result, $context));
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
        $this->emitNodeOutcome($progressCallback, $node, $result, $context);
    }

    /**
     * Emit the terminal SSE state for a settled node, or keep a detached media
     * job in the `running` card state (Sprint B async backbone).
     */
    private function emitNodeOutcome(?callable $progressCallback, TaskNode $node, NodeResult $result, NodeContext $context): void
    {
        if ($result->isRunning()) {
            $this->emitState($progressCallback, $node, 'running', $this->runningMetadata($node, $result));

            return;
        }

        $extra = $result->isSuccessful()
            ? $this->successMetadata($node, $result)
            : $this->failureMetadata($node, $result, $context);
        $this->emitState($progressCallback, $node, $result->isSuccessful() ? 'done' : 'failed', $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function runningMetadata(TaskNode $node, NodeResult $result): array
    {
        $extra = [];
        $mediaJob = $result->metadata['media_job'] ?? null;
        if (is_array($mediaJob)) {
            if (isset($mediaJob['job_id']) && is_string($mediaJob['job_id']) && '' !== $mediaJob['job_id']) {
                $extra['job_id'] = $mediaJob['job_id'];
            }
            if (isset($mediaJob['type']) && is_string($mediaJob['type'])) {
                $extra['media_type'] = $mediaJob['type'];
            }
        }

        if ($this->isMediaKind($node)) {
            $prompt = is_string($result->metadata['media_prompt'] ?? null) ? $result->metadata['media_prompt'] : null;
            if (null !== $prompt && '' !== $prompt) {
                $extra['prompt'] = mb_substr($prompt, 0, self::MAX_PROMPT_LENGTH);
            }
        }

        return $extra;
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
            $this->emitNodeOutcome($progressCallback, $node, $result, $context);

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

        // The media subprocess rebuilds a fresh NodeContext, so the inline-media
        // decision (a dependent needs this node's file in-turn, #1218) cannot
        // ride the context — forward it through options, which MediaGenerationRunner
        // honours as an equivalent to NodeContext::mustRunMediaInline().
        $options = $context->options;
        if ($context->mustRunMediaInline($node->id)) {
            $options['force_inline_media'] = true;
        }

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
            options: $options,
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

    /**
     * Node ids that a PROCESSING node depends on — i.e. every dependency of a
     * node that actually consumes its input (`file_analysis`, `summarize`, …),
     * excluding the `compose_reply` assembler whose dependencies are only
     * gathered for delivery. A media node in this set must render synchronously
     * so the produced file is available in-turn (see {@see execute()} /
     * NodeContext::mustRunMediaInline()); a media node depended on ONLY by
     * compose_reply keeps its async detach.
     *
     * @return list<string>
     */
    private function mediaNodesConsumedByProcessingNodes(TaskPlan $plan): array
    {
        $ids = [];
        foreach ($plan->nodes as $node) {
            if (Capability::ComposeReply === $node->capability) {
                continue;
            }
            foreach ($node->dependsOn as $dep) {
                $ids[$dep] = true;
            }
        }

        return array_keys($ids);
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
        return $this->failedDependency($context, $node);
    }

    /**
     * First dependency that settled as failed/skipped, or null when deps are
     * still running/pending or all succeeded.
     */
    private function failedDependency(NodeContext $context, TaskNode $node): ?string
    {
        foreach ($node->dependsOn as $dep) {
            $r = $context->getResult($dep);
            if (null !== $r && $r->isSettledUnsuccessful()) {
                return $dep;
            }
        }

        return null;
    }

    /**
     * True when a dependency has not finished successfully yet (including async
     * media jobs still in `running`).
     */
    private function blockedByIncompleteDependency(NodeContext $context, TaskNode $node): bool
    {
        foreach ($node->dependsOn as $dep) {
            $r = $context->getResult($dep);
            if (null === $r || !$r->isSuccessful()) {
                return true;
            }
        }

        return false;
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
     * client-side. `$extra` carries failure details (`error`, and for media
     * nodes the resolved `prompt` so the client can retry with another model).
     *
     * @param array<string, mixed> $extra
     */
    private function emitState(?callable $callback, TaskNode $node, string $state, array $extra = []): void
    {
        if (null === $callback) {
            return;
        }
        $callback([
            'status' => 'task_update',
            'message' => '',
            'metadata' => array_merge([
                'node_id' => $node->id,
                'capability' => $node->capability->value,
                'kind' => $node->capability->uiKind(),
                'state' => $state,
            ], $extra),
            'timestamp' => time(),
        ]);
    }

    /**
     * Extra `task_update` metadata for a settled, unsuccessful node: the
     * (truncated) error string, plus — for failed media nodes — the resolved
     * generation prompt and media type so the client can offer a one-click
     * "retry this step with the next model" action.
     *
     * @return array<string, mixed>
     */
    private function failureMetadata(TaskNode $node, NodeResult $result, NodeContext $context): array
    {
        if ($result->isSuccessful()) {
            return [];
        }

        $extra = [];
        if (null !== $result->error && '' !== $result->error) {
            $extra['error'] = mb_substr($result->error, 0, self::MAX_ERROR_LENGTH);
        }

        if (NodeStatus::Failed === $result->status && $this->isMediaKind($node)) {
            $extra['media_type'] = $node->capability->uiKind();
            $prompt = $this->resolveMediaPrompt($node, $context);
            if (null !== $prompt) {
                $extra['prompt'] = $prompt;
            }
        }

        return $extra;
    }

    /**
     * Extra `task_update` metadata for a successfully completed node. Nodes
     * with a search-style card (web_search: query + results_count; url_fetch:
     * fetched hostnames as the query) carry a compact summary so the live task
     * card matches the reload state (QA feedback PR #1076 — card body parity).
     *
     * @return array<string, mixed>
     */
    private function successMetadata(TaskNode $node, NodeResult $result): array
    {
        if (!in_array($node->capability, [Capability::WebSearch, Capability::UrlFetch, Capability::McpFetch, Capability::EmailSearch], true)) {
            return [];
        }

        $extra = [];
        $query = $result->metadata['query'] ?? null;
        if (is_string($query) && '' !== $query) {
            $extra['query'] = $query;
        }
        $sr = $result->metadata['search_results'] ?? null;
        if (is_array($sr) && is_array($sr['results'] ?? null)) {
            $extra['results_count'] = count($sr['results']);
        } elseif (is_int($result->metadata['results_count'] ?? null)) {
            // Data nodes without the web-search result shape (email_search)
            // report their hit count directly.
            $extra['results_count'] = $result->metadata['results_count'];
        }

        return $extra;
    }

    /**
     * Resolve the prompt a media node ran with (mirrors {@see mediaRequest()} /
     * MediaGenerationRunner). Best-effort: input resolution must never break
     * the failure path it decorates.
     */
    private function resolveMediaPrompt(TaskNode $node, NodeContext $context): ?string
    {
        try {
            $inputs = $context->resolveInputs($node);
        } catch (\Throwable) {
            return null;
        }

        $prompt = $this->stringInput($inputs['prompt'] ?? $inputs['text'] ?? null) ?? (string) $context->message->getText();
        if ('' === trim($prompt)) {
            return null;
        }

        return mb_substr($prompt, 0, self::MAX_PROMPT_LENGTH);
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
