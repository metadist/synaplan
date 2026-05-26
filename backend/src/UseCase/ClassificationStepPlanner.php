<?php

declare(strict_types=1);

namespace App\UseCase;

/**
 * Builds runtime step plans from sorter classification (BSTEPS) and signal fallbacks.
 */
final readonly class ClassificationStepPlanner
{
    /** @var array<string, list<array{0: string, 1: string, 2: string}>> */
    private const USE_CASE_DEFAULT_STEPS = [
        'text_chat' => [
            ['chat', 'config.routing.steps.chat', 'CHAT'],
        ],
        'media_generation' => [
            ['generate', 'config.routing.steps.mediaGenerate', 'TEXT2PIC'],
        ],
        'file_generation' => [
            ['create', 'config.routing.steps.fileCreate', 'CHAT'],
        ],
        'file_analytics' => [
            ['extract', 'config.routing.steps.fileExtract', 'ANALYZE'],
            ['answer', 'config.routing.steps.chat', 'CHAT'],
        ],
        'comm_send_email' => [
            ['draft', 'config.routing.steps.draftEmail', 'CHAT'],
            ['send', 'config.routing.steps.sendEmail', 'CHAT'],
        ],
        'comm_receive_email' => [
            ['fetch', 'config.routing.steps.fetchEmail', 'CHAT'],
            ['analyse', 'config.routing.steps.fileExtract', 'ANALYZE'],
        ],
    ];

    /** @var array<string, string> */
    private const CAPABILITY_LABEL_KEYS = [
        'CHAT' => 'config.routing.steps.chat',
        'TEXT2PIC' => 'config.routing.steps.mediaGenerate',
        'TEXT2VID' => 'config.routing.steps.videoGenerate',
        'TEXT2SOUND' => 'config.routing.steps.readAloud',
        'ANALYZE' => 'config.routing.steps.fileExtract',
        'TEXT2DOC' => 'config.routing.steps.fileCreate',
    ];

    public function __construct(
        private UseCaseMapper $useCaseMapper,
    ) {
    }

    /**
     * @param array<string, mixed> $classification
     */
    public function plan(string $messageText, array $classification): StepPlan
    {
        $topic = (string) ($classification['topic'] ?? 'general');
        $granular = isset($classification['granular_topic']) ? (string) $classification['granular_topic'] : null;
        $useCaseId = (string) ($classification['primary_use_case_id'] ?? $this->useCaseMapper->topicToUseCaseId($topic, $granular));

        $sorterPlan = $this->planFromSorterSteps($classification, $useCaseId);
        if (null !== $sorterPlan) {
            return $sorterPlan;
        }

        $signalPlan = $this->planFromClassificationSignals($classification, $useCaseId);
        if (null !== $signalPlan) {
            return $signalPlan;
        }

        $steps = $this->defaultStepsForUseCase($useCaseId, $classification);

        return new StepPlan($useCaseId, $steps, false);
    }

    /**
     * @param array<string, mixed> $classification
     */
    private function planFromSorterSteps(array $classification, string $useCaseId): ?StepPlan
    {
        $rawSteps = $classification['steps'] ?? null;
        if (!is_array($rawSteps) || [] === $rawSteps) {
            return null;
        }

        $classificationWebSearch = filter_var($classification['web_search'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $planned = [];
        foreach ($rawSteps as $rawStep) {
            if (!is_array($rawStep)) {
                continue;
            }

            $id = isset($rawStep['id']) ? (string) $rawStep['id'] : '';
            $capability = isset($rawStep['capability']) ? strtoupper((string) $rawStep['capability']) : '';
            if ('' === $id || '' === $capability) {
                continue;
            }

            $labelKey = isset($rawStep['label_key']) && is_string($rawStep['label_key'])
                ? $rawStep['label_key']
                : (self::CAPABILITY_LABEL_KEYS[$capability] ?? 'config.routing.steps.chat');

            $inputFrom = isset($rawStep['input_from']) && is_string($rawStep['input_from'])
                ? $rawStep['input_from']
                : null;

            $webSearch = filter_var($rawStep['web_search'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (!$webSearch && 'CHAT' === $capability && $classificationWebSearch) {
                $webSearch = true;
            }

            $planned[] = new PlannedStep($id, $labelKey, $capability, $inputFrom, $webSearch);
        }

        if ([] === $planned) {
            return null;
        }

        $isCompound = count($planned) > 1;
        $primaryUseCaseId = $isCompound ? 'text_chat' : $useCaseId;

        return new StepPlan($primaryUseCaseId, $planned, $isCompound);
    }

    /**
     * @param array<string, mixed> $classification
     */
    private function planFromClassificationSignals(array $classification, string $useCaseId): ?StepPlan
    {
        if (!filter_var($classification['web_search'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        $intent = (string) ($classification['intent'] ?? 'chat');
        $mediaType = isset($classification['media_type']) ? (string) $classification['media_type'] : null;

        if ('image_generation' !== $intent && 'media_generation' !== $useCaseId) {
            return null;
        }

        if (null === $mediaType || '' === $mediaType) {
            return null;
        }

        return $this->chatThenMediaPlan($mediaType);
    }

    private function chatThenMediaPlan(string $mediaType): StepPlan
    {
        [$labelKey, $capability] = match ($mediaType) {
            'video' => ['config.routing.steps.videoGenerate', 'TEXT2VID'],
            'audio' => ['config.routing.steps.readAloud', 'TEXT2SOUND'],
            default => ['config.routing.steps.mediaGenerate', 'TEXT2PIC'],
        };

        return new StepPlan('text_chat', [
            new PlannedStep('answer', 'config.routing.steps.chat', 'CHAT', null, true),
            new PlannedStep('generate', $labelKey, $capability),
        ], true);
    }

    /**
     * @param array<string, mixed> $classification
     *
     * @return list<PlannedStep>
     */
    private function defaultStepsForUseCase(string $useCaseId, array $classification): array
    {
        $templates = self::USE_CASE_DEFAULT_STEPS[$useCaseId] ?? self::USE_CASE_DEFAULT_STEPS['text_chat'];
        $steps = array_map(
            static fn (array $row): PlannedStep => new PlannedStep($row[0], $row[1], $row[2]),
            $templates,
        );

        if ('media_generation' !== $useCaseId) {
            return $steps;
        }

        $mediaType = isset($classification['media_type']) ? (string) $classification['media_type'] : '';
        if ('video' === $mediaType) {
            $steps[0] = new PlannedStep('generate', 'config.routing.steps.videoGenerate', 'TEXT2VID');
        } elseif ('audio' === $mediaType) {
            $steps[0] = new PlannedStep('generate', 'config.routing.steps.readAloud', 'TEXT2SOUND');
        }

        return $steps;
    }
}
