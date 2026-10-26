<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\StructuredOutputSchema;

/**
 * The fact-checker's answer for tools:research_check. One verdict per
 * statement id. A missing verdict is treated as unsupported.
 */
final class ResearchCheckSchema
{
    public static function name(): string
    {
        return 'research_check';
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return ResearchJsonSchema::object([
            'verdicts' => ResearchJsonSchema::listOf(ResearchJsonSchema::object([
                'statement' => ResearchJsonSchema::string(),
                'verdict' => ResearchJsonSchema::enum(['supported', 'partial', 'unsupported', 'contradicted']),
                'unsupported_part' => ResearchJsonSchema::nullableString(),
                'note' => ResearchJsonSchema::string(),
            ], ['statement', 'verdict', 'unsupported_part', 'note'])),
        ], ['verdicts']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function example(): array
    {
        return [
            'verdicts' => [[
                'statement' => 'c1',
                'verdict' => 'supported',
                'unsupported_part' => null,
                'note' => 'The passage states the price.',
            ]],
        ];
    }

    public static function build(): StructuredOutputSchema
    {
        return new StructuredOutputSchema(self::name(), self::schema(), true);
    }
}
