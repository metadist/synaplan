<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\UseCase\CompoundRoutingCatalog;
use App\UseCase\StepPlan;
use Psr\Log\LoggerInterface;

/**
 * Tiered classification service that replaces the Qdrant-based SynapseRouter
 * in the routing hot-path.
 *
 * Classification tiers (short-circuit on first confident match):
 *   Tier 1: RouterClient → external SetFit/ONNX service (~10ms)
 *   Tier 2: MessageSorter → LLM-based classification (~200-800ms)
 *
 * The RuleBasedStepPlanner (regex/keyword matching) is handled upstream in
 * MessageClassifier via fast-path heuristics and tool commands. This service
 * handles the AI-driven tiers only.
 *
 * Returns a classification array compatible with what SynapseRouter/MessageSorter
 * produce, plus an optional StepPlan for compound (multi-step) requests.
 */
final readonly class ClassificationService
{
    public function __construct(
        private RouterClient $routerClient,
        private MessageSorter $messageSorter,
        private CompoundRoutingCatalog $compoundCatalog,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Classify a message through the tiered pipeline.
     *
     * @param array $messageData         Message data array (BTEXT, BTOPIC, etc.)
     * @param array $conversationHistory Previous messages for context
     * @param int   $userId              The user ID for per-user config
     *
     * @return array{
     *     topic: string,
     *     language: string,
     *     web_search: bool,
     *     source: string,
     *     step_plan: ?StepPlan,
     *     media_type: ?string,
     *     duration: ?string,
     *     resolution: ?string,
     *     confidence: ?float
     * }
     */
    public function classify(array $messageData, array $conversationHistory = [], int $userId = 0): array
    {
        $text = (string) ($messageData['BTEXT'] ?? '');

        // Tier 1: External SetFit Router (fast, ~10ms)
        $externalResult = $this->tryExternalRouter($text);
        if (null !== $externalResult) {
            return $externalResult;
        }

        // Tier 2: LLM-based sorting (MessageSorter)
        $llmResult = $this->messageSorter->classify($messageData, $conversationHistory, $userId);

        return [
            'topic' => (string) ($llmResult['topic'] ?? 'general'),
            'language' => (string) ($llmResult['language'] ?? 'en'),
            'web_search' => (bool) ($llmResult['web_search'] ?? false),
            'source' => 'llm_sorter',
            'step_plan' => null,
            'media_type' => $llmResult['media_type'] ?? null,
            'duration' => $llmResult['duration'] ?? null,
            'resolution' => $llmResult['resolution'] ?? null,
            'confidence' => null,
            'sorting_model_id' => $llmResult['sorting_model_id'] ?? null,
            'sorting_provider' => $llmResult['sorting_provider'] ?? null,
            'sorting_model_name' => $llmResult['sorting_model_name'] ?? null,
            'raw_response' => $llmResult['raw_response'] ?? null,
        ];
    }

    /**
     * Attempt classification via the external SetFit router.
     *
     * Returns null when the router is unavailable, disabled, or not confident enough.
     */
    private function tryExternalRouter(string $text): ?array
    {
        $result = $this->routerClient->classify($text);

        if (null === $result) {
            return null;
        }

        $confidence = $result['confidence'];
        $threshold = $this->routerClient->getConfidenceThreshold();

        if ($confidence < $threshold) {
            $this->logger->debug('ClassificationService: External router below threshold', [
                'use_case' => $result['use_case'],
                'confidence' => $confidence,
                'threshold' => $threshold,
            ]);

            return null;
        }

        $useCase = $result['use_case'];
        $isCompound = $result['is_compound'];
        $steps = $result['steps'];

        $stepPlan = null;
        if ($isCompound && !empty($steps)) {
            $stepPlan = StepPlan::fromRouterResponse($steps, 'setfit', $confidence);
        } elseif ($this->compoundCatalog->isCompound($useCase)) {
            $catalogSteps = $this->compoundCatalog->getSteps($useCase);
            if (!empty($catalogSteps)) {
                $stepPlan = StepPlan::fromRouterResponse($catalogSteps, 'catalog', $confidence);
            }
        }

        $topic = $this->useCaseToTopic($useCase);

        $this->logger->info('ClassificationService: External router classified', [
            'use_case' => $useCase,
            'topic' => $topic,
            'confidence' => $confidence,
            'is_compound' => $isCompound,
            'model_version' => $result['model_version'],
            'latency_ms' => $result['latency_ms'],
        ]);

        return [
            'topic' => $topic,
            'language' => 'en',
            'web_search' => false,
            'source' => 'external_router',
            'step_plan' => $stepPlan,
            'media_type' => $this->extractMediaType($useCase),
            'duration' => null,
            'resolution' => null,
            'confidence' => $confidence,
        ];
    }

    /**
     * Map a use case label from the external router to a canonical topic.
     */
    private function useCaseToTopic(string $useCase): string
    {
        return match (true) {
            str_contains($useCase, 'image') => 'mediamaker',
            str_contains($useCase, 'video') => 'mediamaker',
            str_contains($useCase, 'audio'), str_contains($useCase, 'tts') => 'mediamaker',
            str_contains($useCase, 'code'), str_contains($useCase, 'coding') => 'general',
            str_contains($useCase, 'office'), str_contains($useCase, 'document') => 'officemaker',
            str_contains($useCase, 'analyze'), str_contains($useCase, 'file') => 'analyzefile',
            str_contains($useCase, 'chat'), str_contains($useCase, 'general') => 'general',
            str_contains($useCase, 'compound') => $this->resolveCompoundTopic($useCase),
            default => 'general',
        };
    }

    private function resolveCompoundTopic(string $useCase): string
    {
        $steps = $this->compoundCatalog->getSteps($useCase);
        if (!empty($steps)) {
            $firstStep = $steps[0];

            return (new \App\UseCase\PlannedStep(
                id: 'step_1',
                capability: strtoupper($firstStep['capability']),
            ))->toTopic();
        }

        return 'general';
    }

    private function extractMediaType(string $useCase): ?string
    {
        if (str_contains($useCase, 'image')) {
            return 'image';
        }
        if (str_contains($useCase, 'video')) {
            return 'video';
        }
        if (str_contains($useCase, 'audio') || str_contains($useCase, 'tts')) {
            return 'audio';
        }

        return null;
    }
}
