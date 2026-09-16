<?php

declare(strict_types=1);

namespace App\Service\Context;

use App\Repository\ModelRepository;

/**
 * Resolves a model's context window from the catalog row (`BJSON.meta`) with
 * a conservative fallback, and turns it into a character budget for one
 * attachment block.
 *
 * The catalog is the single source of truth for per-model limits (see
 * ModelCatalog `meta.context_window` / `meta.max_output`); this service only
 * reads it. When a row carries no metadata the fallback is deliberately the
 * smallest common window of the current frontier models, so a missing value
 * never produces a request that overflows.
 */
final readonly class ModelContextWindow
{
    public function __construct(
        private ModelRepository $modelRepository,
        private TokenEstimator $tokenEstimator,
        private ContextFittingConfig $config,
    ) {
    }

    public function forModel(?int $modelId): ContextWindowSpec
    {
        if (null === $modelId || $modelId <= 0) {
            return $this->fallback(null);
        }

        $model = $this->modelRepository->find($modelId);
        if (null === $model) {
            return $this->fallback($modelId);
        }

        $context = $model->getContextWindowTokens();
        if (null === $context) {
            return $this->fallback($modelId);
        }

        $maxOutput = $model->getMaxOutputTokens() ?? ContextFittingConfig::FALLBACK_MAX_OUTPUT_TOKENS;

        return new ContextWindowSpec(
            contextTokens: $context,
            maxOutputTokens: min($maxOutput, $context),
            source: 'catalog',
            modelId: $modelId,
        );
    }

    /**
     * Character budget one attachment block (file text, fetched pages, …) may
     * take in a request to `$modelId`:
     *
     *   (context − output reservation) × INPUT_SHARE, converted with the
     *   density of the text that has to fit.
     *
     * `$reservedOutputTokens` lets the caller pass the completion budget it
     * intends to request; when omitted the model's catalog `max_output` is
     * reserved (capped so a 128k-output model does not eat the window).
     */
    public function attachmentCharBudget(?int $modelId, string $sample, ?int $userId = null, ?int $reservedOutputTokens = null): int
    {
        $spec = $this->forModel($modelId);
        $reserved = $reservedOutputTokens ?? min($spec->maxOutputTokens, 16000);

        $inputTokens = $spec->inputTokens($reserved);
        $share = $this->config->inputShare($userId);

        return $this->tokenEstimator->charsForTokens((int) floor($inputTokens * $share), $sample);
    }

    /**
     * Completion budget to request from `$modelId` for a document-analysis
     * answer. Reasoning models count their hidden thinking against this
     * number, so the historical flat 4 000 produced visibly truncated replies
     * on GPT-6 Astra; scale with the catalog ceiling instead.
     */
    public function answerOutputTokens(?int $modelId, int $floor = 4000, int $ceiling = 16000): int
    {
        $spec = $this->forModel($modelId);

        return max($floor, min($ceiling, $spec->maxOutputTokens));
    }

    private function fallback(?int $modelId): ContextWindowSpec
    {
        return new ContextWindowSpec(
            contextTokens: ContextFittingConfig::FALLBACK_CONTEXT_TOKENS,
            maxOutputTokens: ContextFittingConfig::FALLBACK_MAX_OUTPUT_TOKENS,
            source: 'fallback',
            modelId: $modelId,
        );
    }
}
