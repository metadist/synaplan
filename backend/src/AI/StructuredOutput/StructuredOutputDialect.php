<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput;

/**
 * The wire shape a provider expects a JSON-schema request in.
 *
 * {@see StructuredOutputCapability} decides which provider speaks which
 * dialect, {@see StructuredOutputTranslator} writes it.
 */
enum StructuredOutputDialect
{
    /** `response_format: {type: "json_schema", json_schema: {...}}` — the openai-php/client-based cluster. */
    case OPENAI_JSON_SCHEMA;

    /** `text: {format: {type: "json_schema", ...}}` — OpenAI Responses API only; NOT the same nesting as Chat Completions. */
    case OPENAI_RESPONSES_TEXT_FORMAT;

    /**
     * `generationConfig: {responseMimeType: "application/json", responseJsonSchema: {...}}` — Google Gemini.
     *
     * NOT the older `responseSchema`: that field takes an OpenAPI 3.0 schema
     * subset which rejects `additionalProperties` and union types, and Google
     * has frozen it in favour of `responseJsonSchema`. The keyword subset the
     * latter accepts is enforced by {@see GoogleJsonSchemaNormalizer}.
     */
    case GOOGLE_RESPONSE_JSON_SCHEMA;

    /** `format: {...}` — Ollama's native structured-output field. */
    case OLLAMA_FORMAT;

    /** Forced single-tool call (`tools` + `tool_choice`) — Anthropic has no native JSON-schema response mode. */
    case ANTHROPIC_TOOL_FORCING;
}
