<?php

declare(strict_types=1);

namespace Plugin\SortX\Service;

use App\Service\PluginDataService;

/**
 * Generates classification prompts from user's category schema.
 *
 * Uses PluginDataService to load categories stored in the generic plugin_data table.
 */
final readonly class PromptGenerator
{
    public const SUPPORTED_LANGUAGES = 'English, German, French, Spanish, Italian, Chinese, Arabic';
    private const PLUGIN_NAME = 'sortx';
    private const DATA_TYPE_CATEGORY = 'category';

    public function __construct(
        private PluginDataService $pluginData,
    ) {
    }

    /**
     * Get the default prompt template with placeholders.
     */
    public function getDefaultPromptTemplate(): string
    {
        return <<<'PROMPT'
You are a document classification assistant. Your task is to classify documents into categories and optionally extract structured metadata.

IMPORTANT:
- Documents may be in any of these languages: {languages}
- A document can belong to MULTIPLE categories (e.g., a contract that is also an invoice)
- If uncertain, use "unknown" category and explain in reasoning
- Respond ONLY with valid JSON (no markdown, no code blocks)

{categories_section}

{fields_section}

OUTPUT FORMAT (JSON only, no markdown):
{
  "categories": ["category_key", ...],
  "confidence": 0.0-1.0,
  "reasoning": "Brief explanation",
  "metadata": {
    "field_key": { "value": "extracted value", "confidence": 0.0-1.0 }
  }
}
PROMPT;
    }

    /**
     * Get available template variables.
     */
    public function getTemplateVariables(): array
    {
        return [
            '{languages}' => 'Supported document languages',
            '{categories_section}' => 'List of available categories',
            '{fields_section}' => 'Metadata fields per category',
        ];
    }

    /**
     * Build the complete schema array for a user (used by /schema endpoint).
     *
     * @return array<int, array{key: string, name: string, description: ?string, fields: array}>
     */
    public function getSchemaForUser(int $userId): array
    {
        $categoriesData = $this->pluginData->list($userId, self::PLUGIN_NAME, self::DATA_TYPE_CATEGORY);
        $schema = [];

        foreach ($categoriesData as $key => $data) {
            // Only include enabled categories
            if (!($data['enabled'] ?? true)) {
                continue;
            }

            $schema[] = [
                'key' => $key,
                'name' => $data['name'] ?? $key,
                'description' => $data['description'] ?? null,
                'fields' => $data['fields'] ?? [],
                'sort_order' => $data['sort_order'] ?? 0,
            ];
        }

        // Sort by sort_order
        usort($schema, fn ($a, $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));

        return $schema;
    }

    /**
     * Generate the classification prompt from schema.
     *
     * @param array<int, array{key: string, name: string, description: ?string, fields: array}> $schema
     */
    public function generatePrompt(array $schema, bool $extractMetadata = false): string
    {
        $prompt = $this->buildSystemContext();
        $prompt .= $this->buildCategorySection($schema);

        if ($extractMetadata) {
            $prompt .= $this->buildFieldsSection($schema);
        }

        $prompt .= $this->buildOutputFormat($extractMetadata);

        return $prompt;
    }

    private function buildSystemContext(): string
    {
        $languages = self::SUPPORTED_LANGUAGES;

        return <<<PROMPT
You are a document classification assistant. Your task is to classify documents into categories and optionally extract structured metadata.

IMPORTANT:
- Documents may be in any of these languages: {$languages}
- A document can belong to MULTIPLE categories (e.g., a contract that is also an invoice)
- If uncertain, use "unknown" category and explain in reasoning
- Respond ONLY with valid JSON (no markdown, no code blocks)


PROMPT;
    }

    /**
     * @param array<int, array{key: string, name: string, description: ?string, fields: array}> $schema
     */
    private function buildCategorySection(array $schema): string
    {
        $section = "AVAILABLE CATEGORIES:\n";

        foreach ($schema as $cat) {
            $desc = $cat['description'] ?? 'No description';
            $section .= "- {$cat['key']}: {$desc}\n";
        }

        $section .= "- unknown: Document does not fit any category or confidence is below threshold\n\n";

        return $section;
    }

    /**
     * @param array<int, array{key: string, name: string, description: ?string, fields: array}> $schema
     */
    private function buildFieldsSection(array $schema): string
    {
        $section = "METADATA FIELDS TO EXTRACT (per category):\n";

        foreach ($schema as $cat) {
            if (empty($cat['fields'])) {
                continue;
            }

            $section .= "\n[{$cat['key']}]\n";

            foreach ($cat['fields'] as $field) {
                $type = $field['type'] ?? 'text';
                if ($type === 'enum' && !empty($field['enum_values'])) {
                    $type = 'one of: '.implode(', ', $field['enum_values']);
                }
                $required = ($field['required'] ?? false) ? ' (required)' : '';
                $section .= "  - {$field['key']} ({$type}){$required}\n";
            }
        }

        $section .= "\n";

        return $section;
    }

    private function buildOutputFormat(bool $extractMetadata): string
    {
        $format = "OUTPUT FORMAT (JSON only, no markdown):\n";
        $format .= "{\n";
        $format .= '  "categories": ["category_key", ...],'."\n";
        $format .= '  "confidence": 0.0-1.0,'."\n";
        $format .= '  "reasoning": "Brief explanation"';

        if ($extractMetadata) {
            $format .= ",\n";
            $format .= '  "metadata": {'."\n";
            $format .= '    "document_date": { "value": "YYYY-MM-DD or null", "confidence": 0.0-1.0 },'."\n";
            $format .= '    "sender": { "value": "who sent/created", "confidence": 0.0-1.0 },'."\n";
            $format .= '    "recipient": { "value": "who receives", "confidence": 0.0-1.0 },'."\n";
            $format .= '    "language": { "value": "ISO 639-1 code", "confidence": 0.0-1.0 },'."\n";
            $format .= '    "text_quality": { "value": "readable|partial|gibberish|empty", "confidence": 0.0-1.0 },'."\n";
            $format .= '    "summary": { "value": "1-2 sentence summary", "confidence": 0.0-1.0 },'."\n";
            $format .= '    "...category_specific_fields...": { "value": "...", "confidence": 0.0-1.0 }'."\n";
            $format .= "  }";
        }

        $format .= "\n}\n";

        $format .= $this->buildGdprInstructions();

        return $format;
    }

    private function buildGdprInstructions(): string
    {
        return <<<'INSTRUCTIONS'

UNIVERSAL FIELDS (always extract):
- document_date: Primary date of the document
- sender: Who created/sent the document  
- recipient: Who receives the document
- language, text_quality, summary: Always include

GDPR ASSESSMENT (for "unknown" category):
When classifying as "unknown", you MUST assess GDPR relevance:
- gdpr_relevant: true if document contains personal data
- gdpr_confidence: 0.0-1.0 confidence in assessment
- gdpr_indicators: What triggered the flag (e.g., "private address", "birthdate", "personal statement")
- topic: Brief topic description (max 4 words)

Personal data indicators to look for:
- Private addresses (street, city, postal code of individuals)
- Birth dates, age, or personal identifiers
- Health information
- Financial details of individuals
- Personal opinions or statements
- Names with context suggesting private matters

INSTRUCTIONS;
    }
}
