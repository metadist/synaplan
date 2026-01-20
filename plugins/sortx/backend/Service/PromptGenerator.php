<?php

declare(strict_types=1);

namespace Plugin\SortX\Service;

use Plugin\SortX\Entity\SortxCategory;
use Plugin\SortX\Entity\SortxCategoryField;
use Plugin\SortX\Repository\SortxCategoryRepository;

final readonly class PromptGenerator
{
    private const SUPPORTED_LANGUAGES = 'English, German, French, Spanish, Italian, Chinese, Arabic';

    public function __construct(
        private SortxCategoryRepository $categoryRepo,
    ) {
    }

    /**
     * Build the complete schema array for a user (used by /schema endpoint).
     *
     * @return array<int, array{key: string, name: string, description: ?string, fields: array<int, array{key: string, name: string, type: string, enum_values: ?array, description: ?string, required: bool}>}>
     */
    public function getSchemaForUser(int $userId): array
    {
        $categories = $this->categoryRepo->findEnabledByUser($userId);
        $schema = [];

        foreach ($categories as $category) {
            $schema[] = $this->categoryToArray($category);
        }

        return $schema;
    }

    /**
     * Convert a category entity to array format.
     *
     * @return array{key: string, name: string, description: ?string, fields: array<int, array{key: string, name: string, type: string, enum_values: ?array, description: ?string, required: bool}>}
     */
    public function categoryToArray(SortxCategory $category): array
    {
        return [
            'key' => $category->getKey(),
            'name' => $category->getName(),
            'description' => $category->getDescription(),
            'fields' => array_map(
                fn (SortxCategoryField $f) => $this->fieldToArray($f),
                $category->getFields()->toArray()
            ),
        ];
    }

    /**
     * Convert a field entity to array format.
     *
     * @return array{key: string, name: string, type: string, enum_values: ?array, description: ?string, required: bool}
     */
    public function fieldToArray(SortxCategoryField $field): array
    {
        return [
            'key' => $field->getFieldKey(),
            'name' => $field->getFieldName(),
            'type' => $field->getFieldType(),
            'enum_values' => $field->getEnumValues(),
            'description' => $field->getDescription(),
            'required' => $field->isRequired(),
        ];
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
        return <<<PROMPT
You are a document classification assistant. Your task is to classify documents into categories and optionally extract structured metadata.

IMPORTANT:
- Documents may be in any of these languages: {self::SUPPORTED_LANGUAGES}
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
                $type = $field['type'];
                if ($type === 'enum' && !empty($field['enum_values'])) {
                    $type = 'one of: '.implode(', ', $field['enum_values']);
                }
                $required = $field['required'] ? ' (required)' : '';
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
            $format .= '    "field_key": { "value": "extracted value", "confidence": 0.0-1.0 }'."\n";
            $format .= "  }";
        }

        $format .= "\n}\n";

        return $format;
    }
}
