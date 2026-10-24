<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\StructuredOutputSchema;

/**
 * One source's findings for tools:research_read. Passage ids and quotes are
 * checked against the page afterwards; a lead URL is kept only from the
 * page's own link list.
 */
final class ResearchEvidenceSchema
{
    public static function name(): string
    {
        return 'research_evidence';
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return ResearchJsonSchema::object([
            'source_id' => ResearchJsonSchema::string(),
            'useful' => ['type' => 'boolean'],
            'published' => ResearchJsonSchema::nullableString(),
            'kind' => ResearchJsonSchema::enum(['official', 'primary', 'academic', 'documentation', 'news', 'vendor', 'forum', 'other']),
            'findings' => ResearchJsonSchema::listOf(ResearchJsonSchema::object([
                'point' => ResearchJsonSchema::string(),
                'fact' => ResearchJsonSchema::string(),
                'passages' => ResearchJsonSchema::listOf(ResearchJsonSchema::string()),
                'quote' => [
                    'type' => ['object', 'null'],
                    'additionalProperties' => false,
                    'properties' => [
                        'text' => ResearchJsonSchema::string(),
                        'passage' => ResearchJsonSchema::string(),
                    ],
                    'required' => ['text', 'passage'],
                ],
                'confidence' => ResearchJsonSchema::enum(['high', 'medium', 'low']),
            ], ['point', 'fact', 'passages', 'quote', 'confidence'])),
            'leads' => ResearchJsonSchema::listOf(ResearchJsonSchema::object([
                'link' => ResearchJsonSchema::string(),
                'point' => ResearchJsonSchema::string(),
            ], ['link', 'point']), 5),
            'flags' => ResearchJsonSchema::listOf(ResearchJsonSchema::enum([
                'outdated', 'opinion_or_advertising', 'contradicts_itself', 'partial_text', 'machine_translation', 'unclear_author',
            ])),
        ], ['source_id', 'useful', 'published', 'kind', 'findings', 'leads', 'flags']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function example(): array
    {
        return [
            'source_id' => 's3',
            'useful' => true,
            'published' => '2026-03-01',
            'kind' => 'vendor',
            'findings' => [[
                'point' => 'p2',
                'fact' => 'Base price 12.40 EUR per unit (net).',
                'passages' => ['§14'],
                'quote' => ['text' => 'Grundpreis netto 12,40 € je Einheit', 'passage' => '§14'],
                'confidence' => 'high',
            ]],
            'leads' => [['link' => 'L7', 'point' => 'p3']],
            'flags' => [],
        ];
    }

    public static function build(): StructuredOutputSchema
    {
        return new StructuredOutputSchema(self::name(), self::schema(), true);
    }
}
