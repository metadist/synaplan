<?php

declare(strict_types=1);

namespace App\Service\Multitask;

use App\Entity\Message;
use App\Service\Message\InferenceRouter;
use App\Service\ModelConfigService;
use App\Service\Multitask\Execution\DagExecutor;
use App\Service\Multitask\Execution\NodeContext;
use Psr\Log\LoggerInterface;

/**
 * Executes a task plan. Drop-in replacement for {@see InferenceRouter} at the
 * MessageProcessor call sites, gated by MULTITASK_ROUTING_ENABLED.
 *
 * Plan source + path selection:
 *   - Only genuinely AI-classified messages (classification source `ai_sorting`)
 *     are sent to the {@see TaskPlanner}. Deterministic/simple branches
 *     (fast-path chat, slash commands, attachments, widget, again) keep using
 *     the proven single-node path — no planner latency, no behaviour change.
 *   - If the planner returns a SINGLE-node plan or falls back, we run the
 *     Sprint-2 degenerate path: delegate to InferenceRouter with the exact
 *     legacy classification (behaviour identical), and persist the plan.
 *   - If the planner returns a MULTI-node plan, run the sequential
 *     {@see DagExecutor}. On total failure (no node succeeded) we fall back to
 *     the legacy router so the user still gets an answer.
 *
 * Sprint 3b note: multi-node output currently surfaces its FIRST file via the
 * existing `metadata['file']` channel (text + one file — the canonical
 * doc→summary→mp3 case) AND the full list via `metadata['files']` for the
 * additive multi-file controller branch.
 */
final readonly class TaskPlanExecutor
{
    public function __construct(
        private InferenceRouter $router,
        private ClassificationPlanMapper $mapper,
        private TaskPlanStore $store,
        private TaskPlanner $planner,
        private DagExecutor $dagExecutor,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Streaming entry point — mirrors {@see InferenceRouter::routeStream()}.
     *
     * @param array<int, Message>  $thread
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function executeStream(
        Message $message,
        array $thread,
        array $classification,
        callable $streamCallback,
        ?callable $progressCallback = null,
        array $options = [],
    ): array {
        $plan = $this->planForExecution($message, $thread, $classification);

        if (null === $plan || $plan->plan->isSingleNode()) {
            return $this->runSingleNode(
                fn () => $this->router->routeStream($message, $thread, $this->effectiveClassification($classification), $streamCallback, $progressCallback, $options),
                $message,
                $classification,
            );
        }

        $assembled = $this->runDag($message, $thread, $classification, $options, $plan, $progressCallback);

        if ($assembled['all_failed']) {
            $this->logger->info('TaskPlanExecutor: DAG produced no successful node, falling back to legacy router', [
                'message_id' => $message->getId(),
            ]);

            return $this->runSingleNode(
                fn () => $this->router->routeStream($message, $thread, $this->effectiveClassification($classification), $streamCallback, $progressCallback, $options),
                $message,
                $classification,
            );
        }

        // Surface the assembled text by streaming it once (populates the OUT text)
        // and return the file(s) in the handler-result metadata shape.
        $streamCallback($assembled['content']);

        return $this->toHandlerResult($assembled);
    }

    /**
     * Non-streaming entry point — mirrors {@see InferenceRouter::route()}.
     *
     * @param array<int, Message>  $thread
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function execute(
        Message $message,
        array $thread,
        array $classification,
        ?callable $progressCallback = null,
        array $options = [],
    ): array {
        $plan = $this->planForExecution($message, $thread, $classification);

        if (null === $plan || $plan->plan->isSingleNode()) {
            return $this->runSingleNode(
                fn () => $this->router->route($message, $thread, $this->effectiveClassification($classification), $progressCallback, $options),
                $message,
                $classification,
            );
        }

        $assembled = $this->runDag($message, $thread, $classification, $options, $plan, $progressCallback);

        if ($assembled['all_failed']) {
            return $this->runSingleNode(
                fn () => $this->router->route($message, $thread, $this->effectiveClassification($classification), $progressCallback, $options),
                $message,
                $classification,
            );
        }

        return $this->toHandlerResult($assembled);
    }

    /**
     * Decide whether to run the planner and, if so, produce a plan. Returns null
     * to mean "use the single-node legacy path" (deterministic/simple branches).
     *
     * @param array<int, Message>  $thread
     * @param array<string, mixed> $classification
     */
    private function planForExecution(Message $message, array $thread, array $classification): ?TaskPlanResult
    {
        // Only AI-sorted messages are candidates for multi-task planning.
        if ('ai_sorting' !== ($classification['source'] ?? null)) {
            return null;
        }

        try {
            $userId = $this->modelConfigService->getEffectiveUserIdForMessage($message);

            return $this->planner->plan($message, $thread, $userId);
        } catch (\Throwable $e) {
            $this->logger->warning('TaskPlanExecutor: planning failed, using single-node path', [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Run the sequential DAG and persist per-node statuses.
     *
     * @param array<int, Message>  $thread
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     *
     * @return array{content: string, files: list<array<string, mixed>>, metadata: array<string, mixed>, node_statuses: array<string, string>, partial_failure: bool, all_failed: bool}
     */
    private function runDag(
        Message $message,
        array $thread,
        array $classification,
        array $options,
        TaskPlanResult $plan,
        ?callable $progressCallback,
    ): array {
        $userId = $plan->modelId ? $this->modelConfigService->getEffectiveUserIdForMessage($message) : $message->getUserId();
        $context = new NodeContext($message, $thread, $userId, $classification, $options);

        // Announce the plan so the web client can render task cards up front. The
        // DagExecutor then emits task_update / task_chunk / task_file directly via
        // the same progress channel (all structured data rides in metadata, which
        // the SSE status callback forwards verbatim).
        if (null !== $progressCallback) {
            $progressCallback([
                'status' => 'plan',
                'message' => '',
                'metadata' => [
                    'plan' => $this->planForClient($plan->plan),
                    'reply_node' => $plan->plan->replyNode,
                ],
                'timestamp' => time(),
            ]);
        }

        $assembled = $this->dagExecutor->execute($plan->plan, $context, $progressCallback);

        $messageId = $message->getId();
        if (null !== $messageId) {
            try {
                $this->store->persistWithStatuses($messageId, $plan->plan, $plan->modelId, $assembled['node_statuses']);
            } catch (\Throwable $e) {
                $this->logger->warning('TaskPlanExecutor: failed to persist DAG plan (ignored)', [
                    'message_id' => $messageId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $assembled;
    }

    /**
     * Build the client-facing task list for the `plan` event. Hidden nodes
     * (compose_reply — the assembler) are excluded; they are not user cards.
     *
     * @return list<array{node_id: string, capability: string, kind: string}>
     */
    private function planForClient(Plan\TaskPlan $plan): array
    {
        $tasks = [];
        foreach ($plan->nodes as $node) {
            $kind = $node->capability->uiKind();
            if ('hidden' === $kind) {
                continue;
            }
            $tasks[] = [
                'node_id' => $node->id,
                'capability' => $node->capability->value,
                'kind' => $kind,
            ];
        }

        return $tasks;
    }

    /**
     * Convert the assembled DAG result into the handler-result shape that
     * StreamController/WebhookController persist. The primary file goes on the
     * legacy `metadata['file']` channel (unchanged single-file path); the full
     * list rides on `metadata['files']` for the additive multi-file branch.
     *
     * @param array{content: string, files: list<array<string, mixed>>, metadata: array<string, mixed>, node_statuses: array<string, string>, partial_failure: bool, all_failed: bool} $assembled
     *
     * @return array<string, mixed>
     */
    private function toHandlerResult(array $assembled): array
    {
        $metadata = $assembled['metadata'];
        $files = $assembled['files'];

        if ([] !== $files) {
            $metadata['file'] = $files[0];
            $metadata['files'] = $files;
        }

        return [
            'content' => $assembled['content'],
            'metadata' => $metadata,
        ];
    }

    /**
     * Run a single-node execution closure and persist the degenerate plan
     * (Sprint-2 behaviour: identical to the legacy router call).
     *
     * @param callable():array<string, mixed> $run
     * @param array<string, mixed>            $classification
     *
     * @return array<string, mixed>
     */
    private function runSingleNode(callable $run, Message $message, array $classification): array
    {
        $result = $run();
        $this->persistPlan($message, $classification, 'done');

        return $result;
    }

    /**
     * Round-trip the classification through a single-node plan so the executor
     * runs on the same array the router would have received. Any failure falls
     * back to the original classification — the answer must never break.
     *
     * @param array<string, mixed> $classification
     *
     * @return array<string, mixed>
     */
    private function effectiveClassification(array $classification): array
    {
        try {
            $plan = $this->mapper->toSingleNodePlan($classification);
            $node = $plan->nodes[0] ?? null;
            $recovered = $node ? $this->mapper->classificationFromNode($node) : null;

            return $recovered ?? $classification;
        } catch (\Throwable $e) {
            $this->logger->warning('TaskPlanExecutor: plan round-trip failed, using original classification', [
                'error' => $e->getMessage(),
            ]);

            return $classification;
        }
    }

    /**
     * @param array<string, mixed> $classification
     */
    private function persistPlan(Message $message, array $classification, string $status): void
    {
        try {
            $messageId = $message->getId();
            if (null === $messageId) {
                return;
            }
            $plan = $this->mapper->toSingleNodePlan($classification);
            $this->store->persist($messageId, $plan, null, $status);
        } catch (\Throwable $e) {
            $this->logger->warning('TaskPlanExecutor: failed to persist executed plan (ignored)', [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
