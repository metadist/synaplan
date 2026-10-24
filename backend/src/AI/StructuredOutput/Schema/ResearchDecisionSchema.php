<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\StructuredOutputSchema;

/**
 * The lead's next step for tools:research_reflect. finish is not an action:
 * when it is set, the engine ignores actions.
 */
final class ResearchDecisionSchema
{
    public static function name(): string
    {
        return 'research_decision';
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return ResearchJsonSchema::object([
            'points' => ResearchJsonSchema::listOf(ResearchJsonSchema::object([
                'id' => ResearchJsonSchema::string(),
                'status' => ResearchJsonSchema::enum(['answered', 'thin', 'conflicting', 'open']),
                'note' => ResearchJsonSchema::string(),
            ], ['id', 'status', 'note'])),
            'actions' => ResearchJsonSchema::listOf(ResearchJsonSchema::object([
                'type' => ResearchJsonSchema::enum(['search', 'read', 'reread', 'ask_user']),
                'point' => ResearchJsonSchema::nullableString(),
                'query' => ResearchJsonSchema::nullableString(),
                'language' => ResearchJsonSchema::nullableString(),
                'scope' => ['type' => ['string', 'null'], 'enum' => ['web', 'files', 'both', null]],
                'source' => ResearchJsonSchema::nullableString(),
                'question' => ResearchJsonSchema::nullableString(),
                'options' => ResearchJsonSchema::listOf(ResearchJsonSchema::string(), 4),
            ], ['type'])),
            'finish' => ['type' => ['string', 'null'], 'enum' => ['covered', 'budget', 'no_progress', null]],
        ], ['points', 'actions', 'finish']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function example(): array
    {
        return [
            'points' => [[
                'id' => 'p1',
                'status' => 'open',
                'note' => 'No source on the warranty yet.',
            ]],
            'actions' => [[
                'type' => 'search',
                'point' => 'p1',
                'query' => 'warranty terms 2026',
                'language' => 'en',
                'scope' => 'web',
                'source' => null,
                'question' => null,
                'options' => [],
            ]],
            'finish' => null,
        ];
    }

    public static function build(): StructuredOutputSchema
    {
        return new StructuredOutputSchema(self::name(), self::schema(), true);
    }
}
