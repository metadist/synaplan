<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput;

/**
 * The wire shape a provider expects a JSON-schema request in, per
 * "Zielarchitektur" in the structured-output refactor plan.
 */
enum StructuredOutputDialect
{
    /** `response_format: {type: "json_schema", json_schema: {...}}` — the openai-php/client-based cluster. */
    case OPENAI_JSON_SCHEMA;

    /** `text: {format: {type: "json_schema", ...}}` — OpenAI Responses API only; NOT the same nesting as Chat Completions. */
    case OPENAI_RESPONSES_TEXT_FORMAT;

    /** `generationConfig: {responseMimeType: "application/json", responseSchema: {...}}` — Google Gemini. */
    case GOOGLE_RESPONSE_SCHEMA;

    /** `format: {...}` — Ollama's native structured-output field. */
    case OLLAMA_FORMAT;

    /** Forced single-tool call (`tools` + `tool_choice`) — Anthropic has no native JSON-schema response mode. */
    case ANTHROPIC_TOOL_FORCING;
}
