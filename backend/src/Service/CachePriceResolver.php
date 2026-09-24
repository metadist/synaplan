<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Model;
use App\Model\ModelCatalog;

/**
 * Resolves the effective cache-read and cache-write rates billing actually charges.
 *
 * Shared by {@see CostCalculationService} and {@see \App\Command\SyncModelPricesCommand}
 * so the daily drift check compares what we bill (including provider fallbacks), not
 * only authored JSON keys. A missing `cache_read_price_per_1M` on an Anthropic row
 * that should read at 0.05x is otherwise invisible — billing silently falls back to
 * 0.1x and overcharges every cache hit.
 */
final readonly class CachePriceResolver
{
    public const float CACHE_READ_DISCOUNT_ANTHROPIC = 0.10;

    // Default TTL (5 minutes) cache write, per https://platform.claude.com/docs/en/build-with-claude/prompt-caching.
    public const float CACHE_WRITE_MULTIPLIER_ANTHROPIC = 1.25;

    // Opt-in 1-hour TTL cache write (`cache_control: {"type": "ephemeral", "ttl": "1h"}`) —
    // billed at 2x base input price. Applies uniformly across the Anthropic lineup.
    public const float CACHE_WRITE_MULTIPLIER_ANTHROPIC_1H = 2.0;

    // Last-resort cache-read rate for rows that author no `cache_read_price_per_1M`.
    // Matches the GPT-4o generation but NOT GPT-5+ / Gemini Pro (those author 0.1x).
    public const float CACHE_READ_DISCOUNT_DEFAULT = 0.50;

    /** @param string $provider canonical provider key (see ModelCatalog::normalizeProvider) */
    public function cacheReadDiscount(string $provider): float
    {
        return 'anthropic' === $provider
            ? self::CACHE_READ_DISCOUNT_ANTHROPIC
            : self::CACHE_READ_DISCOUNT_DEFAULT;
    }

    /**
     * Multiplier applied to the input rate for tokens WRITTEN to the cache (5-minute TTL).
     *
     * Whether a cache write is billed at all is a per-model property: OpenAI started
     * charging 1.25x with GPT-5.6, while earlier models incur no additional write
     * charge (multiplier 1.0 = billed at the input rate). The catalog value wins;
     * Anthropic's provider-wide 1.25x is the fallback for rows that don't author one.
     *
     * @param string $provider canonical provider key (see ModelCatalog::normalizeProvider)
     */
    public function cacheWriteMultiplier(string $provider, Model $model): float
    {
        $authored = $model->getJson()['cache_write_multiplier'] ?? null;
        if (is_numeric($authored)) {
            return (float) $authored;
        }

        return 'anthropic' === $provider
            ? self::CACHE_WRITE_MULTIPLIER_ANTHROPIC
            : 1.0;
    }

    /**
     * @param string $provider canonical provider key (see ModelCatalog::normalizeProvider)
     */
    public function cacheWriteMultiplier1h(string $provider): float
    {
        return 'anthropic' === $provider
            ? self::CACHE_WRITE_MULTIPLIER_ANTHROPIC_1H
            : 1.0;
    }

    /**
     * Effective cache-read USD per 1M tokens — what billing charges for a cache hit.
     *
     * @return array{rate: float, authored: bool, discount: float}
     */
    public function effectiveCacheReadPer1M(Model $model): array
    {
        $provider = ModelCatalog::normalizeProvider($model->getService());
        $discount = $this->cacheReadDiscount($provider);
        $authored = $model->getJson()['cache_read_price_per_1M'] ?? null;

        if (null !== $authored && is_numeric($authored)) {
            return [
                'rate' => (float) $authored,
                'authored' => true,
                'discount' => $discount,
            ];
        }

        return [
            'rate' => $model->getPriceIn() * $discount,
            'authored' => false,
            'discount' => $discount,
        ];
    }

    /** Effective 5-minute cache-write USD per 1M tokens (input × write multiplier). */
    public function effectiveCacheWritePer1M(Model $model): float
    {
        $provider = ModelCatalog::normalizeProvider($model->getService());

        return $model->getPriceIn() * $this->cacheWriteMultiplier($provider, $model);
    }

    /** Effective 1-hour cache-write USD per 1M tokens (input × 1h multiplier). */
    public function effectiveCacheWrite1hPer1M(Model $model): float
    {
        $provider = ModelCatalog::normalizeProvider($model->getService());

        return $model->getPriceIn() * $this->cacheWriteMultiplier1h($provider);
    }
}
