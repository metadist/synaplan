<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\StructuredOutputSchema;

/**
 * JSON schema for {@see \App\Service\WidgetSetupService::generatePromptMetadata()}.
 */
final class WidgetPromptMetadataSchema
{
    public static function build(): StructuredOutputSchema
    {
        return new StructuredOutputSchema(
            name: 'widget_prompt_metadata',
            schema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                ],
                'required' => ['title', 'description'],
            ],
        );
    }
}
