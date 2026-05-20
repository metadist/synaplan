<?php

declare(strict_types=1);

namespace App\UseCase;

/**
 * Rule-based step planner (Release D v1).
 *
 * Mirrors `frontend/src/utils/routingDryRunPreview.ts` — keep patterns in sync.
 */
final readonly class RuleBasedStepPlanner
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

        foreach (self::compoundPatterns() as $pattern) {
            if (1 === preg_match($pattern['test'], $messageText)) {
                return new StepPlan($useCaseId, $pattern['steps'], true);
            }
        }

        $steps = $this->defaultStepsForUseCase($useCaseId, $messageText);

        return new StepPlan($useCaseId, $steps, false);
    }

    /**
     * @return list<PlannedStep>
     */
    private function defaultStepsForUseCase(string $useCaseId, string $messageText): array
    {
        $templates = self::USE_CASE_DEFAULT_STEPS[$useCaseId] ?? self::USE_CASE_DEFAULT_STEPS['text_chat'];
        $steps = array_map(
            static fn (array $row): PlannedStep => new PlannedStep($row[0], $row[1], $row[2]),
            $templates,
        );

        if ('media_generation' !== $useCaseId) {
            return $steps;
        }

        if (1 === preg_match('/\b(video|vid|film|clip|animation)\b/i', $messageText)) {
            $steps[0] = new PlannedStep('generate', 'config.routing.steps.videoGenerate', 'TEXT2VID');
        } elseif (1 === preg_match('/\b(audio|tts|vorlesen|read aloud|speech|sound|sprachausgabe|mp3)\b/i', $messageText)) {
            $steps[0] = new PlannedStep('generate', 'config.routing.steps.readAloud', 'TEXT2SOUND');
        }

        return $steps;
    }

    /**
     * @return list<array{test: non-empty-string, steps: list<PlannedStep>}>
     */
    private static function compoundPatterns(): array
    {
        return [
            [
                'test' => '/\b(und|and|dann|then)\b.*\b(vorlesen|lies(?:\s+\w+){0,2}\s+vor|read(?:\s+\w+){0,2}\s+aloud|tts|text.?to.?speech|sprachausgabe)\b/i',
                'steps' => [
                    new PlannedStep('write', 'config.routing.steps.chat', 'CHAT'),
                    new PlannedStep('speak', 'config.routing.steps.readAloud', 'TEXT2SOUND', 'steps.write.output.text'),
                ],
            ],
            [
                'test' => '/\b(und|and|dann|then)\b.*\b(mail|email|e-mail|schick|send)\b/i',
                'steps' => [
                    new PlannedStep('generate', 'config.routing.steps.mediaGenerate', 'TEXT2PIC'),
                    new PlannedStep('send', 'config.routing.steps.sendEmail', 'CHAT', 'steps.generate.output.text'),
                ],
            ],
            [
                'test' => '/\b(mail|email|e-mail|gemailt|mailed)\b.*\b(gestern|yesterday|hol|fetch|get)\b/i',
                'steps' => [
                    new PlannedStep('fetch', 'config.routing.steps.fetchEmail', 'CHAT'),
                    new PlannedStep('analyse', 'config.routing.steps.fileExtract', 'ANALYZE', 'steps.fetch.output.text'),
                ],
            ],
        ];
    }
}
