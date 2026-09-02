<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput;

/**
 * Answers "can this provider + model + streaming-mode honour a JSON schema
 * request?" — the single place the hard, provider-specific rules from the
 * structured-output refactor plan live, so {@see StructuredOutputTranslator}
 * and every call site can stay ignorant of them.
 *
 * Hard rules encoded here (see plan "Verifizierte Ausgangslage"):
 *   - Groq supports `response_format.json_schema` but 400s when combined with
 *     streaming or tool use. Sorter/Planner/etc. calls are all non-streaming,
 *     but the guard must exist so a future streaming caller can't 400.
 *   - Triton has no server-side constrained decoding — never supported.
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

        return null !== $this->dialect($providerName);
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
