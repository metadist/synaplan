<?php

declare(strict_types=1);

namespace App\Service\File\Office;

/**
 * When Collabora convert-to is configured, teach the AI sorter and multitask
 * planner that creating a real PDF is officemaker / document_generation.
 *
 * Seeded catalog copy still says PDFs are unsupported so OSS installs without
 * OFFICE_CONVERT_URL keep today's behaviour. This decorator rewrites the live
 * prompts only when {@see OfficeConverterClient::isEnabled()} is true — the
 * same gate as thumbnails, export and BEXPORT.
 */
final readonly class OfficePdfRoutingDecorator
{
    public const OFFICEMAKER_TOPIC = 'officemaker';

    private const PLANNER_PDF_UNSUPPORTED = 'Real PDFs are NOT supported — say so in a single `chat` node.';

    private const PLANNER_PDF_SUPPORTED = 'Creating a real PDF is supported: plan `document_generation` the same way as a Word file (the server converts the Office source to PDF). Turning a file that ALREADY EXISTS in the conversation into a PDF ("export this as PDF", "hieraus eine PDF") is a single `document_export` node — NOT extract_text + document_generation, which would re-write the file. Merging two or more existing office/PDF attachments into one PDF ("merge these into one PDF", "führe beide dateien in eine pdf zusammen") is a single `document_combine` node — NOT analyzefile and NOT document_generation.';

    private const PLANNER_GENERATOR_NODES = 'document_generation, calendar_event), surfaced through `compose_reply`.';

    private const PLANNER_GENERATOR_NODES_WITH_EXPORT = 'document_generation, document_export, document_combine, calendar_event), surfaced through `compose_reply`.';

    private const PLANNER_HARD_RULE_PDF = "If the user asks for output the capability list cannot produce (e.g. a real\n   PDF, a phone call)";

    private const PLANNER_HARD_RULE_NO_PDF = "If the user asks for output the capability list cannot produce (e.g. a\n   phone call)";

    private const PLANNER_OFFICE_FORMATS = 'Office document (XLSX, DOCX, PPTX, CSV)';

    private const PLANNER_OFFICE_FORMATS_WITH_PDF = 'Office document (XLSX, DOCX, PPTX, CSV, PDF)';

    private const SORTER_MARKER = 'OFFICE_PDF_ROUTING';

    public function __construct(
        private OfficeConverterClient $officeConverter,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->officeConverter->isEnabled();
    }

    /**
     * @param list<array<string, mixed>> $topics
     *
     * @return list<array<string, mixed>>
     */
    public function decorateTopics(array $topics): array
    {
        if (!$this->isEnabled()) {
            return $topics;
        }

        foreach ($topics as $i => $item) {
            if (self::OFFICEMAKER_TOPIC === ($item['topic'] ?? '')) {
                $topics[$i]['description'] = self::officeMakerDescription();
            }
        }

        return $topics;
    }

    public function decoratePrompt(string $prompt): string
    {
        if (!$this->isEnabled()) {
            return $prompt;
        }

        $prompt = str_replace(self::PLANNER_PDF_UNSUPPORTED, self::PLANNER_PDF_SUPPORTED, $prompt);
        $prompt = str_replace(self::PLANNER_HARD_RULE_PDF, self::PLANNER_HARD_RULE_NO_PDF, $prompt);
        $prompt = str_replace(self::PLANNER_OFFICE_FORMATS, self::PLANNER_OFFICE_FORMATS_WITH_PDF, $prompt);
        $prompt = str_replace(self::PLANNER_GENERATOR_NODES, self::PLANNER_GENERATOR_NODES_WITH_EXPORT, $prompt);

        if (!str_contains($prompt, self::SORTER_MARKER)) {
            $prompt .= self::sorterAppendix();
        }

        return $prompt;
    }

    public static function officeMakerDescription(): string
    {
        return 'The user asks to generate OR to modify/reformat a single Excel, PowerPoint, Word or PDF document (CSV, XLSX, DOCX, PPTX, PDF). A request to create, generate or export a PDF ("create a PDF", "generate a PDF", "Erstelle mir ein PDF") is this topic — not general and not synaplan. Follow-up edits of a document generated earlier in the same conversation also belong here. Handles exactly ONE document.';
    }

    private static function sorterAppendix(): string
    {
        return <<<'PROMPT'

## OFFICE_PDF_ROUTING
When the user asks to CREATE a PDF (not "can you make PDFs?"), set BTOPIC to "officemaker". Do not use general or synaplan for produce-a-PDF requests. Turning a file that already exists in the conversation into a PDF is also "officemaker" — the planner then converts that file instead of writing a new one. Merging several attached office/PDF files into one PDF is the same topic — the planner then combines those files instead of analysing them.
PROMPT;
    }
}
