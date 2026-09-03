<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\StructuredOutputSchema;

/**
 * JSON schema for {@see \App\Service\FeedbackExampleService}'s per-source
 * summarization calls — shared by both the knowledge-base variant
 * (`summarizeSourcesWithAi()`) and the web-research variant
 * (`summarizeWebSourcesWithAi()`), which return the identical
 * `{"summaries": [...]}` shape.
 */
final class SourceSummariesSchema
{
    public static function build(): StructuredOutputSchema
    {
        return new StructuredOutputSchema(
            name: 'source_summaries',
            schema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'summaries' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['summaries'],
            ],
        );
    }
}
