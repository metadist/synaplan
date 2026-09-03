<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\StructuredOutputSchema;

/**
 * JSON schema for the `officemaker` topic's file-generation envelope,
 * consumed by {@see \App\Service\File\FileGenerationEnvelope}.
 *
 * Unlike the other schemas in this namespace, this one does not wrap an
 * array: the officemaker prompt (see {@see \App\Prompt\PromptCatalog})
 * already produces exactly the two-key root object
 * `{"BFILEPATH": "…", "BFILETEXT": "…"}`, so no envelope adjustment is
 * needed for OpenAI-dialect structured output or Anthropic tool-forcing.
 *
 * Both fields are always present — unlike the action-list schemas
 * elsewhere in this namespace, officemaker has no optional/nullable
 * fields, so this schema needs no nullable-vs-omittable workaround.
 *
 * `ChatHandler` only attaches this schema when the resolved topic is
 * `officemaker`; every other topic keeps its free-form chat completion.
 * Providers without schema support keep relying purely on the prompt's
 * "respond with PURE JSON" instruction plus
 * {@see \App\Service\File\FileGenerationEnvelope}'s tolerant prose/fence
 * extraction — unchanged.
 */
final class FileGenerationSchema
{
    public static function build(): StructuredOutputSchema
    {
        return new StructuredOutputSchema(
            name: 'office_file_generation',
            schema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'BFILEPATH' => ['type' => 'string'],
                    'BFILETEXT' => ['type' => 'string'],
                ],
                'required' => ['BFILEPATH', 'BFILETEXT'],
            ],
        );
    }
}
