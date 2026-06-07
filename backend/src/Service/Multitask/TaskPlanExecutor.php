<?php

declare(strict_types=1);

namespace App\Service\Multitask;

use App\Entity\Message;
use App\Service\Message\InferenceRouter;
use Psr\Log\LoggerInterface;

/**
 * Executes a task plan. Drop-in replacement for {@see InferenceRouter} at the
 * MessageProcessor call sites, gated by MULTITASK_ROUTING_ENABLED.
 *
 * SPRINT 2 SCOPE (degenerate / single-node): the plan is derived from the
 * legacy `$classification` via {@see ClassificationPlanMapper}, which carries
 * the classification verbatim. The executor recovers that exact classification
 * and delegates to the existing InferenceRouter — so observable behaviour
 * (streaming, status events, handler choice, model selection, fallback) is
 * IDENTICAL to the legacy path. The only addition is persisting the executed
 * plan to BMESSAGE_TASKS (best-effort, never affects the turn).
 *
 * Sprint 3 will replace the single-node delegation with real DAG execution
 * (TaskPlanner multi-node plans + per-capability runners + ResultAssembler)
 * without changing these entry-point signatures.
 */
final readonly class TaskPlanExecutor
{
    public function __construct(
        private InferenceRouter $router,
        private ClassificationPlanMapper $mapper,
        private TaskPlanStore $store,
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
        $effective = $this->effectiveClassification($classification);

        $result = $this->router->routeStream($message, $thread, $effective, $streamCallback, $progressCallback, $options);

        $this->persistPlan($message, $classification, 'done');

        return $result;
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
        $effective = $this->effectiveClassification($classification);

        $result = $this->router->route($message, $thread, $effective, $progressCallback, $options);

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
