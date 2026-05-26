<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Entity\Message;
use App\Repository\ConfigRepository;
use App\UseCase\PlannedStep;
use App\UseCase\StepPlan;
use Psr\Log\LoggerInterface;

/**
 * Executes multi-step plans produced by RuleBasedStepPlanner (Release D).
 *
 * Single-step plans bypass this service — MessageProcessor keeps the legacy
 * one-hop path for the common case.
 */
final readonly class StepOrchestrator
{
    public function __construct(
        private InferenceRouter $router,
        private ConfigRepository $configRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function isEnabled(): bool
    {
        $value = $this->configRepository->getValue(0, 'QDRANT_SEARCH', 'STEP_PLANNER_ENABLED');

        return filter_var($value ?? 'false', FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array<int, array<string, mixed>> $thread
     * @param array<string, mixed>             $classification
     * @param array<string, mixed>             $options
     *
     * @return array<string, mixed>
     */
    public function executeStream(
        Message $message,
        array $thread,
        array $classification,
        StepPlan $plan,
        callable $streamCallback,
        ?callable $statusCallback,
        array $options = [],
    ): array {
        $totalSteps = count($plan->steps);
        /** @var array<string, array{text?: string, metadata?: array<string, mixed>}> $stepOutputs */
        $stepOutputs = [];
        $lastResult = [];
        $completedSteps = 0;

        $this->notify($statusCallback, 'step_plan', 'Multi-step plan ready', [
            'primary_use_case_id' => $plan->primaryUseCaseId,
            'is_compound' => $plan->isCompound,
            'step_count' => $totalSteps,
            'steps' => array_map(static fn (PlannedStep $s): array => $s->toArray(), $plan->steps),
        ]);

        foreach ($plan->steps as $index => $step) {
            $stepNumber = $index + 1;

            $this->notify($statusCallback, 'step_started', 'Step started', [
                'step_id' => $step->id,
                'step_index' => $index,
                'step_number' => $stepNumber,
                'step_total' => $totalSteps,
                'label_key' => $step->labelKey,
                'capability' => $step->capability,
            ]);

            try {
                $stepClassification = $this->buildStepClassification($message, $classification, $step);
                $stepOptions = $this->buildStepOptions($options, $step, $stepOutputs, $message, $plan, $index, $classification);

                $accumulatedText = '';
                $wrappedStream = function ($chunk) use ($streamCallback, &$accumulatedText): void {
                    if (is_string($chunk)) {
                        $accumulatedText .= $chunk;
                        $streamCallback($chunk);

                        return;
                    }

                    if (is_array($chunk)) {
                        $type = $chunk['type'] ?? 'content';
                        if ('content' === $type && isset($chunk['content']) && is_string($chunk['content'])) {
                            $accumulatedText .= $chunk['content'];
                        }
                    }

                    $streamCallback($chunk);
                };

                $lastResult = $this->router->routeStream(
                    $message,
                    $thread,
                    $stepClassification,
                    $wrappedStream,
                    $statusCallback,
                    $stepOptions,
                );

                $stepText = trim($accumulatedText);
                if ('' === $stepText) {
                    $stepText = trim((string) ($lastResult['metadata']['response_text'] ?? ''));
                }

                $stepOutputs[$step->id] = [
                    'text' => $stepText,
                    'metadata' => $lastResult['metadata'] ?? [],
                ];

                ++$completedSteps;

                $this->notify($statusCallback, 'step_completed', 'Step completed', [
                    'step_id' => $step->id,
                    'step_index' => $index,
                    'step_number' => $stepNumber,
                    'step_total' => $totalSteps,
                    'label_key' => $step->labelKey,
                    'capability' => $step->capability,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('StepOrchestrator: Step failed', [
                    'step_id' => $step->id,
                    'step_index' => $index,
                    'error' => $e->getMessage(),
                ]);

                $this->notify($statusCallback, 'step_failed', 'Step failed', [
                    'step_id' => $step->id,
                    'step_index' => $index,
                    'step_number' => $stepNumber,
                    'step_total' => $totalSteps,
                    'label_key' => $step->labelKey,
                    'partial_success' => $completedSteps > 0,
                    'error' => $e->getMessage(),
                ]);

                if (0 === $completedSteps) {
                    throw $e;
                }

                return [
                    'metadata' => $lastResult['metadata'] ?? [],
                    'partial_success' => true,
                    'completed_steps' => $completedSteps,
                    'failed_step_id' => $step->id,
                    'step_outputs' => $stepOutputs,
                ];
            }
        }

        return [
            'metadata' => $lastResult['metadata'] ?? [],
            'partial_success' => false,
            'completed_steps' => $completedSteps,
            'step_outputs' => $stepOutputs,
        ];
    }

    /**
     * @param array<string, mixed> $classification
     *
     * @return array<string, mixed>
     */
    private function buildStepClassification(Message $message, array $classification, PlannedStep $step): array
    {
        $stepClassification = $classification;
        unset($stepClassification['override_model_id']);

        $intent = match ($step->capability) {
            'TEXT2PIC', 'TEXT2VID', 'TEXT2SOUND' => 'image_generation',
            'ANALYZE' => 'file_analysis',
            default => 'chat',
        };

        if ('ANALYZE' === $step->capability && !$this->messageHasAnalyzableAttachment($message)) {
            $intent = 'chat';
        }

        $mediaType = match ($step->capability) {
            'TEXT2VID' => 'video',
            'TEXT2SOUND' => 'audio',
            'TEXT2PIC' => 'image',
            default => null,
        };

        $stepClassification['intent'] = $intent;
        if (null !== $mediaType) {
            $stepClassification['media_type'] = $mediaType;
            $stepClassification['topic'] = 'mediamaker';
            unset($stepClassification['model_id'], $stepClassification['override_model_id']);
        } elseif ('file_analysis' === $intent) {
            $stepClassification['topic'] = 'analyzefile';
        } elseif ('chat' === $intent) {
            $stepClassification['topic'] = 'general';
            $stepClassification['granular_topic'] = 'general-chat';
            unset($stepClassification['media_type']);
        } else {
            $stepClassification['topic'] = $classification['topic'] ?? 'general';
        }

        $stepClassification['orchestrator_step_id'] = $step->id;
        $stepClassification['orchestrator_capability'] = $step->capability;

        return $stepClassification;
    }

    /**
     * @param array<string, mixed>                                                 $options
     * @param array<string, array{text?: string, metadata?: array<string, mixed>}> $stepOutputs
     *
     * @return array<string, mixed>
     */
    private function buildStepOptions(
        array $options,
        PlannedStep $step,
        array $stepOutputs,
        Message $message,
        StepPlan $plan,
        int $stepIndex,
        array $classification,
    ): array {
        $stepOptions = $options;
        if ('CHAT' !== $step->capability) {
            unset($stepOptions['resolved_prompt_data']);
        }

        if (null !== $step->inputFrom && '' !== $step->inputFrom) {
            $resolved = $this->resolveStepInput($step->inputFrom, $stepOutputs);
            if ('' !== $resolved) {
                $stepOptions['step_prompt_text'] = $resolved;
            }
        }

        if ('send' === $step->id && 'CHAT' === $step->capability) {
            $imageContext = $stepOutputs['generate']['metadata']['image_url']
                ?? $stepOutputs['generate']['metadata']['file']['path']
                ?? null;
            if (is_string($imageContext) && '' !== $imageContext) {
                $base = (string) ($stepOptions['step_prompt_text'] ?? $message->getText());
                $stepOptions['step_prompt_text'] = trim($base."\n\nReference media: ".$imageContext);
            }
            $stepOptions['orchestrator_deferred_action'] = 'comm_send_email';
        }

        if ('fetch' === $step->id) {
            $stepOptions['orchestrator_deferred_action'] = 'comm_receive_email';
            if (empty($stepOptions['step_prompt_text'])) {
                $stepOptions['step_prompt_text'] = $message->getText();
            }
        }

        $nextStep = $plan->steps[$stepIndex + 1] ?? null;
        if ('CHAT' === $step->capability && null !== $nextStep && $this->isVisualMediaCapability($nextStep->capability)) {
            $stepOptions['orchestrator_pending_media'] = true;
        }

        if ('TEXT2SOUND' === $step->capability && null !== $step->inputFrom && empty($stepOptions['step_prompt_text'])) {
            $this->logger->warning('StepOrchestrator: TTS step missing text from prior step', [
                'step_id' => $step->id,
                'input_from' => $step->inputFrom,
            ]);
        }

        $stepNeedsSearch = $step->webSearch
            || ('CHAT' === $step->capability && filter_var($classification['web_search'] ?? false, FILTER_VALIDATE_BOOLEAN));

        if ($this->isMediaCapability($step->capability)) {
            unset($stepOptions['search_results']);
            $stepOptions['orchestrator_media_step'] = true;
        } elseif ('CHAT' === $step->capability && !$stepNeedsSearch) {
            unset($stepOptions['search_results']);
        }

        return $stepOptions;
    }

    private function isMediaCapability(string $capability): bool
    {
        return in_array($capability, ['TEXT2PIC', 'TEXT2VID', 'TEXT2SOUND'], true);
    }

    private function isVisualMediaCapability(string $capability): bool
    {
        return in_array($capability, ['TEXT2PIC', 'TEXT2VID'], true);
    }

    /**
     * @param array<string, array{text?: string, metadata?: array<string, mixed>}> $stepOutputs
     */
    private function resolveStepInput(string $inputFrom, array $stepOutputs): string
    {
        if (!preg_match('/^steps\.([^.]+)\.output\.(\w+)$/', $inputFrom, $matches)) {
            return '';
        }

        $stepId = $matches[1];
        $field = $matches[2];

        if ('text' === $field) {
            return trim((string) ($stepOutputs[$stepId]['text'] ?? ''));
        }

        if ('metadata' === $field) {
            $meta = $stepOutputs[$stepId]['metadata'] ?? [];

            return [] !== $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : '';
        }

        return '';
    }

    private function messageHasAnalyzableAttachment(Message $message): bool
    {
        if ($message->getFile() > 0) {
            return true;
        }

        return $message->getFiles()->count() > 0;
    }

    private function notify(?callable $callback, string $status, string $message, array $metadata = []): void
    {
        if (!$callback) {
            return;
        }

        $callback([
            'status' => $status,
            'message' => $message,
            'metadata' => $metadata,
            'timestamp' => microtime(true),
        ]);
    }
}
