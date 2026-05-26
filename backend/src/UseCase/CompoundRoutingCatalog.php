<?php

declare(strict_types=1);

namespace App\UseCase;

/**
 * Static catalog of known compound (multi-step) use cases.
 *
 * Serves two purposes:
 * 1. Training data export for the external synaplan-router (SetFit model)
 * 2. Fallback compound detection when the external router is unavailable
 *
 * Each entry defines:
 * - steps: the ordered capabilities to execute
 * - example_queries: representative user messages (DE + EN) for training
 */
final class CompoundRoutingCatalog
{
    /**
     * @var array<string, array{steps: list<array{capability: string, web_search?: bool, media_type?: string}>, example_queries: list<string>}>
     */
    private const COMPOUNDS = [
        'compound_research_image' => [
            'steps' => [
                ['capability' => 'CHAT', 'web_search' => true],
                ['capability' => 'IMAGE_GENERATION', 'media_type' => 'image'],
            ],
            'example_queries' => [
                'Recherchiere den Bitcoin-Preis und generiere ein Bild davon',
                'Was kostet ein Tesla Model 3 und erstelle ein Bild dazu',
                'Research the latest Mars photos and generate an image based on that',
                'Find out about the weather in Tokyo and create a picture of it',
                'Suche nach dem aktuellen Goldpreis und male ein Bild dazu',
                'Look up the Eiffel Tower height and generate an illustration',
                'Recherchiere aktuelle KI-Trends und erstelle eine Infografik',
                'Search for popular cocktail recipes and create an image of one',
            ],
        ],
        'compound_write_audio' => [
            'steps' => [
                ['capability' => 'CHAT'],
                ['capability' => 'AUDIO_GENERATION', 'media_type' => 'audio'],
            ],
            'example_queries' => [
                'Schreib ein Gedicht und lies es mir vor',
                'Verfasse eine kurze Geschichte und vertone sie',
                'Write a poem about nature and read it aloud',
                'Create a short story and convert it to speech',
                'Schreib mir einen Witz und sprich ihn aus',
                'Compose a haiku and narrate it',
                'Erstelle einen Zungenbrecher und lies ihn vor',
                'Write a motivational quote and speak it',
            ],
        ],
        'compound_image_email' => [
            'steps' => [
                ['capability' => 'IMAGE_GENERATION', 'media_type' => 'image'],
                ['capability' => 'EMAIL_SEND'],
            ],
            'example_queries' => [
                'Generiere ein Logo und maile es an Peter',
                'Erstelle ein Bild von einem Sonnenuntergang und schick es per Mail an info@example.com',
                'Generate a company logo and email it to john@example.com',
                'Create a birthday card image and send it via email to Sarah',
                'Male ein Porträt und sende es an meinen Chef',
                'Design a poster and email it to the marketing team',
            ],
        ],
        'compound_research_file' => [
            'steps' => [
                ['capability' => 'CHAT', 'web_search' => true],
                ['capability' => 'FILE_GENERATION'],
            ],
            'example_queries' => [
                'Recherchiere aktuelle Marktdaten und erstelle eine PowerPoint',
                'Suche nach den Top 10 Programmiersprachen und mache ein PDF daraus',
                'Research AI companies and create a Word document summary',
                'Find the latest sales data and generate an Excel report',
                'Recherchiere Reiseziele in Japan und erstelle eine Präsentation',
                'Look up competitor pricing and compile it into a spreadsheet',
            ],
        ],
        'compound_file_analyze_reply' => [
            'steps' => [
                ['capability' => 'FILE_ANALYSIS'],
                ['capability' => 'CHAT'],
            ],
            'example_queries' => [
                'Analysiere das angehängte Dokument und fasse es zusammen',
                'Lies die PDF und erkläre mir die wichtigsten Punkte',
                'Analyze the attached file and give me a summary',
                'Read this document and explain the key findings',
                'Schau dir die Tabelle an und interpretiere die Zahlen',
                'Parse this CSV and tell me the trends',
            ],
        ],
    ];

    /**
     * Get all compound definitions.
     *
     * @return array<string, array{steps: list<array>, example_queries: list<string>}>
     */
    public static function all(): array
    {
        return self::COMPOUNDS;
    }

    /**
     * Get a specific compound definition.
     *
     * @return array{steps: list<array>, example_queries: list<string>}|null
     */
    public static function get(string $compoundId): ?array
    {
        return self::COMPOUNDS[$compoundId] ?? null;
    }

    /**
     * Build a StepPlan from a compound ID.
     */
    public static function buildStepPlan(string $compoundId, string $source = 'catalog', float $confidence = 1.0): ?StepPlan
    {
        $compound = self::get($compoundId);
        if (null === $compound) {
            return null;
        }

        $steps = [];
        foreach ($compound['steps'] as $i => $stepData) {
            $steps[] = new PlannedStep(
                id: 'step_'.($i + 1),
                capability: $stepData['capability'],
                webSearch: (bool) ($stepData['web_search'] ?? false),
                mediaType: $stepData['media_type'] ?? null,
            );
        }

        return new StepPlan(steps: $steps, source: $source, confidence: $confidence);
    }

    /**
     * Check if a use case ID refers to a known compound.
     */
    public static function isCompound(string $useCaseId): bool
    {
        return isset(self::COMPOUNDS[$useCaseId]);
    }

    /**
     * Get steps definition for a compound use case.
     *
     * @return list<array{capability: string, web_search?: bool, media_type?: string}>
     */
    public static function getSteps(string $useCaseId): array
    {
        return self::COMPOUNDS[$useCaseId]['steps'] ?? [];
    }

    /**
     * Export all training data in JSONL format for the external router.
     *
     * @return list<array{text: string, label: string, source: string}>
     */
    public static function exportTrainingData(): array
    {
        $data = [];

        foreach (self::COMPOUNDS as $label => $compound) {
            foreach ($compound['example_queries'] as $query) {
                $data[] = [
                    'text' => $query,
                    'label' => $label,
                    'source' => 'catalog',
                ];
            }
        }

        return $data;
    }
}
