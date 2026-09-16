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
 * `BEXPORT` is the one optional field: the prompt's "PDF export" section
 * (see {@see \App\Prompt\PromptCatalog}) asks for `"BEXPORT":"pdf"` when
 * the user wants a PDF, and {@see \App\Service\File\FileGenerationEnvelope}
 * reads it. It is modelled nullable-and-required, as strict mode demands —
 * leaving it out of a closed (`additionalProperties: false`) schema made a
 * best-effort provider 400 the whole turn (`json_validate_failed`) the
 * moment the model followed the prompt, and a strict provider silently
 * strip the PDF request instead.
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
    /** The only export target the envelope understands ({@see \App\Service\File\FileGenerationEnvelope}). */
    public const EXPORT_PDF = 'pdf';

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
                    'BEXPORT' => ['type' => ['string', 'null'], 'enum' => [self::EXPORT_PDF, null]],
                ],
                'required' => ['BFILEPATH', 'BFILETEXT', 'BEXPORT'],
            ],
        );
    }
}
