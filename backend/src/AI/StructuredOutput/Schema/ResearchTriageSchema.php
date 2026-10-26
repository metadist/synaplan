<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\StructuredOutputSchema;

/** Which search results are worth reading, for tools:research_triage. */
final class ResearchTriageSchema
{
    public static function name(): string
    {
        return 'research_triage';
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return ResearchJsonSchema::object([
            'results' => ResearchJsonSchema::listOf(ResearchJsonSchema::object([
                'id' => ResearchJsonSchema::string(),
                'point' => ResearchJsonSchema::nullableString(),
                'kind' => ResearchJsonSchema::enum(['official', 'primary', 'academic', 'documentation', 'news', 'vendor', 'forum', 'other']),
                'fresh' => ['type' => ['boolean', 'null']],
                'read' => ['type' => 'boolean'],
                'reason' => ResearchJsonSchema::string(),
            ], ['id', 'point', 'kind', 'fresh', 'read', 'reason'])),
        ], ['results']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function example(): array
    {
        return [
            'results' => [[
                'id' => 'r7',
                'point' => 'p2',
                'kind' => 'official',
                'fresh' => true,
                'read' => true,
                'reason' => 'Price list on the manufacturer site',
            ]],
        ];
    }

    public static function build(): StructuredOutputSchema
    {
        return new StructuredOutputSchema(self::name(), self::schema(), true);
    }
}
