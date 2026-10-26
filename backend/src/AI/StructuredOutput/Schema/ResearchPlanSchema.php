<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\StructuredOutputSchema;

/**
 * The lead's plan for tools:research_plan. The cost estimate in a live answer
 * is replaced by the run's own budget; the example shows the shape only.
 */
final class ResearchPlanSchema
{
    public static function name(): string
    {
        return 'research_plan';
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return ResearchJsonSchema::object([
            'points' => ResearchJsonSchema::listOf(ResearchJsonSchema::object([
                'id' => ResearchJsonSchema::string(),
                'title' => ResearchJsonSchema::string(),
                'why' => ResearchJsonSchema::string(),
                'queries' => ResearchJsonSchema::listOf(ResearchJsonSchema::object([
                    'q' => ResearchJsonSchema::string(),
                    'language' => ResearchJsonSchema::string(),
                ], ['q', 'language'])),
                'scope' => ResearchJsonSchema::enum(['web', 'files', 'both']),
            ], ['id', 'title', 'why', 'queries', 'scope'])),
            'outline' => ResearchJsonSchema::listOf(ResearchJsonSchema::string()),
            'estimate' => ResearchJsonSchema::object([
                'minutes' => ['type' => 'number'],
                'cost' => ['type' => 'number'],
            ], ['minutes', 'cost']),
        ], ['points', 'outline', 'estimate']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function example(): array
    {
        return [
            'points' => [[
                'id' => 'p1',
                'title' => 'Who offers X in Germany',
                'why' => 'The goal depends on it.',
                'queries' => [['q' => 'Anbieter X Deutschland 2026', 'language' => 'de']],
                'scope' => 'web',
            ]],
            'outline' => ['Summary', 'Suppliers', 'Prices and terms', 'Not confirmed'],
            'estimate' => ['minutes' => 3, 'cost' => 0.35],
        ];
    }

    public static function build(): StructuredOutputSchema
    {
        return new StructuredOutputSchema(self::name(), self::schema(), true);
    }
}
