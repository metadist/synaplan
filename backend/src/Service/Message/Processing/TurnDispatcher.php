<?php

namespace App\Service\Message\Processing;

use App\Entity\Message;
use App\Service\Infrastructure\PerfTimer;
use App\Service\Message\Handler\MessageHandlerInterface;
use App\Service\Message\InferenceRouter;
use App\Service\ModelConfigService;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Multitask\TaskPlanExecutor;
use App\Service\Multitask\TaskPlanner;
use App\Service\Multitask\TaskPlanStore;
use Psr\Log\LoggerInterface;

/**
 * Hands a classified turn to the handler that answers it: through the task
 * plan executor when multi-task routing is on for the user, otherwise through
 * the inference router. For single-node plans the executor delegates to the
 * same router with the same classification; it only also persists the plan.
 */
final readonly class TurnDispatcher
{
    public function __construct(
        private InferenceRouter $router,
        private TaskPlanExecutor $taskPlanExecutor,
        private TaskPlanner $taskPlanner,
        private TaskPlanStore $taskPlanStore,
        private MultitaskRoutingConfig $multitaskConfig,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger,
    ) {
    }

    /** The `generating` status, naming the user's chat model. */
    public function announce(Message $message, TurnStatus $status): void
    {
        $chatModelId = $this->modelConfigService->getDefaultModel('CHAT', $message->getUserId());
        $status->report('generating', 'Generating response...', [
            'model_id' => $chatModelId,
            'provider' => $chatModelId ? $this->modelConfigService->getProviderForModel($chatModelId) : null,
            'model_name' => $chatModelId ? $this->modelConfigService->getModelName($chatModelId) : null,
        ]);
    }

    /**
     * @param array<int, Message|array{role: string, content: string}> $history
     * @param array<string, mixed>                                     $classification
     * @param array<string, mixed>                                     $options        forwarded as-is: the DAG runners
     *                                                                                 need track_id / node_id to bind async jobs
     *
     * @return array<string, mixed>
     */
    public function dispatchBuffered(Message $message, array $history, array $classification, ?callable $statusCallback, array $options): array
    {
        return $this->isMultitaskRoutingEnabled($message)
            ? $this->taskPlanExecutor->execute($message, $history, $classification, $statusCallback, $options)
            : $this->router->route($message, $history, $classification, $statusCallback, $options);
    }

    /**
     * @param array<int, Message|array{role: string, content: string}> $history
     * @param array<string, mixed>                                     $classification
     * @param array<string, mixed>                                     $options
     *
     * @return array<string, mixed>
     */
    public function dispatchStreamed(Message $message, array $history, array $classification, callable $streamCallback, ?callable $statusCallback, array $options): array
    {
        return $this->isMultitaskRoutingEnabled($message)
            ? $this->taskPlanExecutor->executeStream($message, $history, $classification, $streamCallback, $statusCallback, $options)
            : $this->router->routeStream($message, $history, $classification, $streamCallback, $statusCallback, $options);
    }

    /**
     * Fold a handler's {@see MessageHandlerInterface::EFFECTIVE_CLASSIFICATION_KEY}
     * report into the classification the caller persists. When a handler
     * answered under a different route than it was dispatched with (a
     * misrouted "audio" request answered as chat), the stored turn must
     * describe that answer, or "Again" re-runs media that was never made.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    public function applyEffectiveClassification(array $classification, array $response): array
    {
        $effective = $response['metadata'][MessageHandlerInterface::EFFECTIVE_CLASSIFICATION_KEY] ?? null;
        if (!is_array($effective) || [] === $effective) {
            return $classification;
        }

        foreach ($effective as $key => $value) {
            if (null === $value) {
                unset($classification[$key]);
                continue;
            }
            $classification[$key] = $value;
        }

        $this->logger->info('MessageProcessor: handler reported an effective classification', [
            'topic' => $classification['topic'] ?? null,
            'intent' => $classification['intent'] ?? null,
            'rerouted_from' => $classification['rerouted_from'] ?? null,
        ]);

        return $classification;
    }

    /**
     * Mark a Saved Task run whichever branch classified it: the task id lets
     * the executor replay the task's pinned steps instead of re-planning.
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function tagSavedTaskRun(array $classification, array $options): array
    {
        $taskId = $options['saved_task_id'] ?? null;
        if (is_int($taskId) && $taskId > 0) {
            $classification['saved_task_id'] = $taskId;
        }

        return $classification;
    }

    /**
     * Shadow mode: plan the turn and persist the plan for analysis without
     * executing it. Inert unless MULTITASK_SHADOW_MODE is on; best effort, so
     * it can never affect the turn. Skipped for slash hints (no billable
     * planning call) and transient messages (nothing to persist against).
     *
     * @param array<int, Message|array{role: string, content: string}> $history
     * @param array<string, mixed>                                     $classification
     */
    public function shadowPlan(Message $message, array $history, ?PerfTimer $perfTimer, array $classification): void
    {
        try {
            if (!$this->multitaskConfig->isShadowMode() || !empty($classification['slash_hint']) || null === $message->getId()) {
                return;
            }

            $userId = $this->modelConfigService->getEffectiveUserIdForMessage($message);
            $perfTimer?->start('plan_shadow');
            try {
                $result = $this->taskPlanner->plan($message, $history, $userId);
            } finally {
                $perfTimer?->stop('plan_shadow');
            }

            $messageId = $message->getId();
            $this->taskPlanStore->persist($messageId, $result->plan, $result->modelId);

            $this->logger->info('MessageProcessor: shadow task plan generated', [
                'message_id' => $messageId,
                'fallback' => $result->fallback,
                'node_count' => count($result->plan->nodes),
                'capabilities' => array_map(static fn ($n) => $n->capability->value, $result->plan->nodes),
                'plan_model_id' => $result->modelId,
                'validation_errors' => $result->errors,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('MessageProcessor: shadow task planning failed (ignored)', [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolved for the effective user (email / WhatsApp remapping parity);
     * false on any lookup error, so a glitch never breaks a turn.
     */
    private function isMultitaskRoutingEnabled(Message $message): bool
    {
        try {
            return $this->multitaskConfig->isRoutingEnabled($this->modelConfigService->getEffectiveUserIdForMessage($message));
        } catch (\Throwable $e) {
            $this->logger->warning('MessageProcessor: routing-flag lookup failed, using legacy path', [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
