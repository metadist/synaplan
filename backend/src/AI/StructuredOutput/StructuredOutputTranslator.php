<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput;

/**
 * Translates a provider-agnostic {@see StructuredOutputSchema} into the
 * request parameters a specific provider dialect expects.
 *
 * Providers call this from inside their own `chat()`/`chatStream()` payload
 * construction (Phase 2 of the structured-output refactor) — this class does
 * not know how to merge its output into a request array; that's the
 * provider's job, since each provider already owns its own payload shape.
 */
final readonly class StructuredOutputTranslator
{
    public function __construct(
        private StructuredOutputCapability $capability,
    ) {
    }

    /**
     * @return array<string, mixed> extra request parameters to merge into the
     *                              provider's payload, or an empty array
     *                              when the provider/model/streaming
     *                              combination doesn't support structured
     *                              output — callers MUST fall back to the
     *                              legacy prose-instruction + decode path
     *                              in that case, never error out
     */
    public function translate(string $providerName, ?string $model, bool $streaming, StructuredOutputSchema $schema): array
    {
        if (!$this->capability->supports($providerName, $model, $streaming)) {
            return [];
        }

        $dialect = $this->capability->dialect($providerName);
        $strict = $schema->strict && $this->capability->supportsStrict($providerName, $model);

        return match ($dialect) {
            StructuredOutputDialect::OPENAI_JSON_SCHEMA => [
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => $schema->name,
                        'schema' => $schema->schema,
                        'strict' => $strict,
                    ],
                ],
            ],
            // Responses API nests under `text.format`, NOT `response_format`
            // — a different parameter name and a different nesting level
            // than Chat Completions, even though both are "OpenAI".
            StructuredOutputDialect::OPENAI_RESPONSES_TEXT_FORMAT => [
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => $schema->name,
                        'schema' => $schema->schema,
                        'strict' => $strict,
                    ],
                ],
            ],
            StructuredOutputDialect::GOOGLE_RESPONSE_SCHEMA => [
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'responseSchema' => $schema->schema,
                ],
            ],
            StructuredOutputDialect::OLLAMA_FORMAT => [
                'format' => $schema->schema,
            ],
            // Anthropic has no native JSON-schema response mode. Force a
            // single tool call whose input_schema IS the desired shape, then
            // the caller reads the tool_use block's `input` instead of
            // message content. See AnthropicProvider for the response-side
            // handling this requires.
            StructuredOutputDialect::ANTHROPIC_TOOL_FORCING => [
                'tools' => [[
                    'name' => $schema->name,
                    'description' => 'Return the structured result for '.$schema->name.'. Always call this tool exactly once with the complete result.',
                    'input_schema' => $schema->schema,
                ]],
                'tool_choice' => ['type' => 'tool', 'name' => $schema->name],
            ],
            null => [],
        };
    }
}
