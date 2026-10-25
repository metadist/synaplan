<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\StructuredOutputSchema;

/**
 * Arguments of the finish tool. The lead ends the loop and gives each point
 * a status. The report is still written afterwards.
 */
final class ResearchFinishSchema
{
    public static function name(): string
    {
        return 'research_finish';
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return ResearchJsonSchema::object([
            'reason' => ResearchJsonSchema::enum(['covered', 'budget', 'no_progress']),
            'points' => ResearchJsonSchema::listOf(ResearchJsonSchema::object([
                'id' => ResearchJsonSchema::string(),
                'status' => ResearchJsonSchema::enum(['answered', 'thin', 'conflicting', 'open']),
                'note' => ResearchJsonSchema::string(),
            ], ['id', 'status', 'note'])),
        ], ['reason', 'points']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function example(): array
    {
        return [
            'reason' => 'covered',
            'points' => [[
                'id' => 'p1',
                'status' => 'answered',
                'note' => 'Two official sources agree.',
            ]],
        ];
    }

    public static function build(): StructuredOutputSchema
    {
        return new StructuredOutputSchema(self::name(), self::schema(), true);
    }
}
