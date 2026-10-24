<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\StructuredOutputSchema;

/**
 * What the sorter returns for tools:research_brief. Questions are accepted
 * and stored later; this step does not wait for an answer.
 */
final class ResearchBriefSchema
{
    public static function name(): string
    {
        return 'research_brief';
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return ResearchJsonSchema::object([
            'goal' => ResearchJsonSchema::string(),
            'output' => ResearchJsonSchema::enum(['answer', 'report', 'table', 'comparison', 'list']),
            'audience' => ResearchJsonSchema::string(),
            'language' => ResearchJsonSchema::string(),
            'time_frame' => [
                'type' => ['object', 'null'],
                'additionalProperties' => false,
                'properties' => [
                    'from' => ResearchJsonSchema::nullableString(),
                    'to' => ResearchJsonSchema::nullableString(),
                ],
                'required' => ['from', 'to'],
            ],
            'region' => ResearchJsonSchema::nullableString(),
            'must_use' => ResearchJsonSchema::listOf(ResearchJsonSchema::string()),
            'must_avoid' => ResearchJsonSchema::listOf(ResearchJsonSchema::string()),
            'ambiguity' => ResearchJsonSchema::listOf(ResearchJsonSchema::object([
                'id' => ResearchJsonSchema::string(),
                'text' => ResearchJsonSchema::string(),
                'options' => ResearchJsonSchema::listOf(ResearchJsonSchema::string()),
                'allow_text' => ['type' => 'boolean'],
            ], ['id', 'text', 'options', 'allow_text']), 3),
        ], ['goal', 'output', 'audience', 'language', 'time_frame', 'region', 'must_use', 'must_avoid', 'ambiguity']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function example(): array
    {
        return [
            'goal' => 'Which suppliers deliver X in Germany, at what prices and terms',
            'output' => 'comparison',
            'audience' => 'purchasing team',
            'language' => 'de',
            'time_frame' => ['from' => '2025-10-17', 'to' => '2026-10-17'],
            'region' => 'DE',
            'must_use' => [],
            'must_avoid' => ['forum'],
            'ambiguity' => [[
                'id' => 'q1',
                'text' => 'Which region should the comparison cover?',
                'options' => ['Germany', 'EU', 'Worldwide'],
                'allow_text' => true,
            ]],
        ];
    }

    public static function build(): StructuredOutputSchema
    {
        return new StructuredOutputSchema(self::name(), self::schema(), true);
    }
}
