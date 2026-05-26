<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Message\ProcessStepCommand;
use App\UseCase\PlannedStep;
use App\UseCase\StepPlan;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Multi-step execution orchestrator.
 *
 * Given a StepPlan with multiple steps:
 * - Step 1 is executed synchronously (streamed to the user)
 * - Steps 2+ are dispatched to the Messenger queue
 *
 * For single-step plans, delegates directly without overhead.
 */
final readonly class StepOrchestrator
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Determine whether a classification result represents a compound request
     * and build a StepPlan accordingly.
     *
     * @param array $classification The output from MessageClassifier::classify()
     */
    public function buildPlan(array $classification): StepPlan
    {
        $steps = $classification['router_steps'] ?? null;

        if (is_array($steps) && count($steps) > 1) {
            $this->logger->info('StepOrchestrator: Building compound plan from router steps', [
                'step_count' => count($steps),
                'source' => $classification['classification_source'] ?? 'unknown',
            ]);

            return StepPlan::fromRouterResponse(
                $steps,
                source: (string) ($classification['classification_source'] ?? 'setfit'),
                confidence: (float) ($classification['classification_confidence'] ?? 0.0),
            );
        }

        $capability = $this->topicToCapability($classification['topic'] ?? 'general');

        return StepPlan::single(
            $capability,
            source: $classification['source'] ?? 'classifier',
            confidence: 1.0,
        );
    }

    /**
     * Check if a step plan requires multi-step orchestration.
     */
    public function requiresOrchestration(StepPlan $plan): bool
    {
        return $plan->isCompound();
    }

    /**
     * Prepare the context for executing a specific step.
     *
     * Enriches the message data with step-specific configuration so the
     * handler system knows what capability to invoke.
     *
     * @param array       $messageData    Base message data (BTEXT, etc.)
     * @param PlannedStep $step           The step to prepare for
     * @param string|null $previousOutput Output from the previous step (fed as context)
     *
     * @return array Enriched message data for this step
     */
    public function prepareStepContext(array $messageData, PlannedStep $step, ?string $previousOutput = null): array
    {
        $context = $messageData;
        $context['_step_id'] = $step->id;
        $context['_step_capability'] = $step->capability;
        $context['_step_topic'] = $step->toTopic();
        $context['_step_web_search'] = $step->webSearch;

        if (null !== $step->mediaType) {
            $context['_step_media_type'] = $step->mediaType;
        }

        if (null !== $previousOutput) {
            $context['_step_previous_output'] = $previousOutput;
        }

        return $context;
    }

    /**
     * Dispatch remaining steps (2+) to the Messenger queue.
     *
     * Called after Step 1 has been executed synchronously.
     *
     * @param StepPlan $plan            The full step plan
     * @param int      $conversationId  The conversation/chat ID
     * @param int      $originalMsgId   The user's original message ID
     * @param int      $userId          User ID
     * @param string   $firstStepOutput Output from step 1 (passed as context)
     */
    public function dispatchRemainingSteps(
        StepPlan $plan,
        int $conversationId,
        int $originalMsgId,
        int $userId,
        string $firstStepOutput = '',
    ): void {
        $steps = $plan->steps;

        if (count($steps) <= 1) {
            return;
        }

        $previousOutput = $firstStepOutput;

        for ($i = 1, $count = count($steps); $i < $count; ++$i) {
            $step = $steps[$i];

            $this->logger->info('StepOrchestrator: Dispatching step to queue', [
                'step_index' => $i,
                'step_id' => $step->id,
                'capability' => $step->capability,
                'conversation_id' => $conversationId,
            ]);

            $this->messageBus->dispatch(new ProcessStepCommand(
                conversationId: $conversationId,
                originalMsgId: $originalMsgId,
                userId: $userId,
                stepIndex: $i,
                stepData: $step->toArray(),
                previousOutput: $previousOutput,
            ));

            // Subsequent steps in the queue will receive their previous output
            // from the handler — we only pass step 1's output to step 2 here.
            $previousOutput = '';
        }
    }

    /**
     * Map a canonical topic to the capability enum used by StepPlan.
     */
    private function topicToCapability(string $topic): string
    {
        return match ($topic) {
            'mediamaker' => 'IMAGE_GENERATION',
            'officemaker' => 'FILE_GENERATION',
            'analyzefile' => 'FILE_ANALYSIS',
            default => 'CHAT',
        };
    }
}
