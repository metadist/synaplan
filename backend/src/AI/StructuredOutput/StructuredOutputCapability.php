<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput;

/**
 * Answers "can this provider + model + streaming-mode honour a JSON schema
 * request?" — the single place the hard, provider-specific rules from the
 * structured-output refactor plan live, so {@see StructuredOutputTranslator}
 * and every call site can stay ignorant of them.
 *
 * Hard rules encoded here, each verified against the provider in question:
 *   - Groq supports `response_format.json_schema` but 400s when combined with
 *     streaming or tool use. Sorter/Planner/etc. calls are all non-streaming,
 *     but the guard must exist so a future streaming caller can't 400.
 *   - Triton has no server-side constrained decoding — never supported.
 *   - Anthropic expresses structured output as a FORCED tool call, which some
 *     Claude generations reject outright — those models are unsupported here
 *     rather than 400ing at request time.
 *   - `strict: true` mode requires `additionalProperties: false` and every
 *     property in `required`; only documented, tested models may request it.
 */
final class StructuredOutputCapability
{
    /**
     * Providers with no JSON-schema-shaped response mode at all, regardless
     * of model or streaming mode.
     */
    private const NEVER_SUPPORTED_PROVIDERS = ['triton'];

    /**
     * Providers using the OpenAI Chat Completions `response_format.json_schema`
     * dialect. All are openai-php/client-based and forward `$parameters`
     * unfiltered (verified against the vendored SDK — see plan).
     */
    private const OPENAI_JSON_SCHEMA_PROVIDERS = ['groq', 'mistral', 'xai', 'trustedtokens', 'openaicompatible', 'huggingface'];

    private const OPENAI_RESPONSES_PROVIDERS = ['openai'];

    private const GOOGLE_PROVIDERS = ['google'];

    private const OLLAMA_PROVIDERS = ['ollama'];

    private const ANTHROPIC_PROVIDERS = ['anthropic'];

    /**
     * Providers that 400 when a schema request is combined with streaming.
     */
    private const NO_SCHEMA_WITH_STREAMING = ['groq'];

    /**
     * Anthropic models that reject a FORCED `tool_choice`
     * (`{"type": "any"}` / `{"type": "tool", "name": ...}`) with a 400
     * invalid_request_error — only `auto` and `none` are accepted.
     *
     * Since {@see StructuredOutputDialect::ANTHROPIC_TOOL_FORCING} IS a forced
     * tool call, structured output is simply not available on these models and
     * they fall back to the prose-instruction path. Matched by prefix because
     * Anthropic ships dated aliases of the same model
     * (`claude-fable-5-1-20260812` and friends), all of which share the
     * restriction.
     *
     * Only the forcing is affected: the native tool-calling routing path sends
     * `tool_choice: auto` and works on these models normally.
     */
    private const ANTHROPIC_NO_FORCED_TOOL_CHOICE_PREFIXES = ['claude-fable-5-1', 'claude-mythos-5-1'];

    /**
     * Models allowed to request `strict: true` mode, keyed by (lowercased)
     * provider name. Deliberately an allow-list: strict mode's
     * `additionalProperties: false` + full-required constraint has only been
     * verified against these.
     *
     * @var array<string, list<string>>
     */
    private const STRICT_CAPABLE_MODELS = [
        // The shipped SORT/PLAN default (see DefaultModelConfigSeeder).
        'groq' => ['openai/gpt-oss-120b', 'openai/gpt-oss-20b'],
    ];

    public function supports(string $providerName, ?string $model, bool $streaming): bool
    {
        $provider = strtolower($providerName);

        if (in_array($provider, self::NEVER_SUPPORTED_PROVIDERS, true)) {
            return false;
        }

        if ($streaming && in_array($provider, self::NO_SCHEMA_WITH_STREAMING, true)) {
            return false;
        }

        if (StructuredOutputDialect::ANTHROPIC_TOOL_FORCING === $this->dialect($provider)
            && self::rejectsForcedToolChoice($model)
        ) {
            return false;
        }

        return null !== $this->dialect($providerName);
    }

    private static function rejectsForcedToolChoice(?string $model): bool
    {
        if (null === $model) {
            return false;
        }

        $normalized = strtolower($model);

        foreach (self::ANTHROPIC_NO_FORCED_TOOL_CHOICE_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function supportsStrict(string $providerName, ?string $model): bool
    {
        if (null === $model) {
            return false;
        }

        $provider = strtolower($providerName);

        return in_array($model, self::STRICT_CAPABLE_MODELS[$provider] ?? [], true);
    }

    public function dialect(string $providerName): ?StructuredOutputDialect
    {
        $provider = strtolower($providerName);

        return match (true) {
            in_array($provider, self::OPENAI_JSON_SCHEMA_PROVIDERS, true) => StructuredOutputDialect::OPENAI_JSON_SCHEMA,
            in_array($provider, self::OPENAI_RESPONSES_PROVIDERS, true) => StructuredOutputDialect::OPENAI_RESPONSES_TEXT_FORMAT,
            in_array($provider, self::GOOGLE_PROVIDERS, true) => StructuredOutputDialect::GOOGLE_RESPONSE_SCHEMA,
            in_array($provider, self::OLLAMA_PROVIDERS, true) => StructuredOutputDialect::OLLAMA_FORMAT,
            in_array($provider, self::ANTHROPIC_PROVIDERS, true) => StructuredOutputDialect::ANTHROPIC_TOOL_FORCING,
            default => null,
        };
    }
}
