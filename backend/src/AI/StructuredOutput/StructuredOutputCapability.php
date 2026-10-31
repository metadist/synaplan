<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput;

use App\AI\Catalog\ProviderCatalog;
use App\AI\Catalog\ProviderDialects;

/**
 * Answers "can this provider + model + streaming-mode honour a JSON schema
 * request?" — the single place the hard, provider-specific rules from the
 * structured-output refactor plan are applied, so {@see StructuredOutputTranslator}
 * and every call site can stay ignorant of them.
 *
 * The rules themselves live on each provider's descriptor
 * ({@see ProviderDialects}): which dialect it speaks (none for Triton), whether
 * a schema may travel with streaming (Groq 400s), which models reject the
 * dialect (Anthropic generations that refuse a forced tool_choice) and which
 * models may request `strict: true`. A capability built with `new` — as the
 * providers' constructor defaults do — reads the shipped catalog; the
 * container injects its own.
 */
final class StructuredOutputCapability
{
    private readonly ProviderCatalog $catalog;

    public function __construct(?ProviderCatalog $catalog = null)
    {
        $this->catalog = $catalog ?? ProviderCatalog::builtIn();
    }

    public function supports(string $providerName, ?string $model, bool $streaming): bool
    {
        $dialects = $this->dialects($providerName);
        if (null === $dialects?->structuredOutput) {
            return false;
        }

        if ($streaming && !$dialects->schemaWithStreaming) {
            return false;
        }

        return !self::rejectsDialect($dialects, $model);
    }

    private static function rejectsDialect(ProviderDialects $dialects, ?string $model): bool
    {
        if (null === $model) {
            return false;
        }

        $normalized = strtolower($model);

        foreach ($dialects->structuredOutputUnavailableModelPrefixes as $prefix) {
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

        // Model ids come from BMODELS, where the same model has shipped with
        // differing case; every other comparison in this class lowercases, so
        // this one must too, or a capitalised id silently downgrades to the
        // non-strict schema.
        return in_array(strtolower($model), $this->dialects($providerName)->strictSchemaModels ?? [], true);
    }

    public function dialect(string $providerName): ?StructuredOutputDialect
    {
        return $this->dialects($providerName)?->structuredOutput;
    }

    private function dialects(string $providerName): ?ProviderDialects
    {
        return $this->catalog->find($providerName)?->dialects;
    }
}
