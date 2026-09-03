<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\StructuredOutputSchema;

/**
 * JSON schema for {@see \App\Service\FeedbackContradictionService}'s
 * contradiction-detection call (both the single-statement and the batch
 * variant share this response shape).
 */
final class FeedbackContradictionSchema
{
    private const TYPES = ['memory', 'false_positive', 'positive'];

    public static function build(): StructuredOutputSchema
    {
        return new StructuredOutputSchema(
            name: 'feedback_contradiction',
            schema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'contradictions' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'type' => ['type' => 'string', 'enum' => self::TYPES],
                                'value' => ['type' => 'string'],
                                'reason' => ['type' => 'string'],
                            ],
                            'required' => ['id', 'type', 'value', 'reason'],
                        ],
                    ],
                ],
                'required' => ['contradictions'],
            ],
        );
    }
}
