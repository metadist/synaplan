<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput;

/**
 * A provider-agnostic description of the JSON shape a chat call must return.
 *
 * Call-sites pass this via `$options['structured_output']` to
 * {@see \App\AI\Service\AiFacade::chat()} / `chatStream()` — deliberately NOT
 * `response_format`, so the OpenAI wire dialect never leaks into the internal
 * contract. Each provider's {@see StructuredOutputTranslator} target maps this
 * into its own dialect (response_format.json_schema, text.format,
 * generationConfig.responseSchema, format, or a forced tool call).
 *
 * `$schema` is plain JSON Schema (draft-07-ish subset every provider dialect
 * accepts: object/string/number/boolean/array/enum, `properties`,
 * `required`, `additionalProperties`). Optional fields MUST be modeled as
 * nullable types (`"type": ["string", "null"]`) rather than omitted from
 * `required`, because {@see $strict} mode (where supported) requires every
 * property to be listed in `required` with `additionalProperties: false`.
 */
final readonly class StructuredOutputSchema
{
    /**
     * @param string               $name   short machine name for the schema (e.g. "sort_classification");
     *                                     surfaced to some dialects (OpenAI json_schema.name, Anthropic tool name)
     * @param array<string, mixed> $schema JSON Schema describing the expected object shape
     * @param bool                 $strict whether to request the provider's strict/schema-guaranteed mode when
     *                                     available ({@see StructuredOutputCapability::supportsStrict()}); ignored
     *                                     (treated as false) where the provider or model doesn't support it
     */
    public function __construct(
        public string $name,
        public array $schema,
        public bool $strict = true,
    ) {
    }
}
