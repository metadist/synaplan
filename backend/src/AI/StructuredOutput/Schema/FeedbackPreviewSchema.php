<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\StructuredOutputSchema;

/**
 * JSON schema for {@see \App\Service\FeedbackExampleService::previewFalsePositive()}'s
 * combined summary/correction/classification call.
 */
final class FeedbackPreviewSchema
{
    private const CLASSIFICATIONS = ['memory', 'feedback'];

    public static function build(): StructuredOutputSchema
    {
        return new StructuredOutputSchema(
            name: 'feedback_preview',
            schema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'classification' => ['type' => 'string', 'enum' => self::CLASSIFICATIONS],
                    'summaryOptions' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'correctionOptions' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['classification', 'summaryOptions', 'correctionOptions'],
            ],
        );
    }
}
