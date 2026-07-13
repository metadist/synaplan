<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\CostResult;
use App\Entity\Model;
use App\Model\ModelCatalog;
use App\Repository\ModelPriceHistoryRepository;
use App\Repository\ModelRepository;
use Psr\Log\LoggerInterface;

final readonly class CostCalculationService
{
    private const CACHE_READ_DISCOUNT_ANTHROPIC = 0.10;
    private const CACHE_WRITE_MULTIPLIER_ANTHROPIC = 1.25;
    private const CACHE_READ_DISCOUNT_DEFAULT = 0.50;

    public function __construct(
        private ModelRepository $modelRepository,
        private ModelPriceHistoryRepository $priceHistoryRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function calculateCost(
        int $promptTokens,
        int $completionTokens,
        int $cachedTokens,
        int $cacheCreationTokens,
        ?int $modelId,
        ?int $timestamp = null,
    ): CostResult {
        if (!$modelId) {
            return $this->zeroCostResult();
        }

        $model = $this->modelRepository->find($modelId);
        if (!$model) {
            $this->logger->warning('CostCalculation: Model not found', ['model_id' => $modelId]);

            return $this->zeroCostResult();
        }

        $priceSnapshot = $this->getPriceSnapshot($model, $timestamp);
        $priceIn = (float) $priceSnapshot['price_in'];
        $priceOut = (float) $priceSnapshot['price_out'];
        $cachePriceIn = $priceSnapshot['cache_price_in'];
        $provider = $model->getService();

        if ($priceIn <= 0 && $priceOut <= 0) {
            return new CostResult(
                totalCost: '0.000000',
                inputCost: '0.000000',
                outputCost: '0.000000',
                cacheSavings: '0.000000',
                priceSnapshot: $priceSnapshot,
                billedInputTokens: $promptTokens,
            );
        }

        // Long-context tier: several providers bill the WHOLE request at a
        // higher per-token rate once the prompt crosses a token threshold
        // (Gemini/Claude >200k, GPT-5.x >272k). Switch both input and output to
        // the above-threshold rate — matching how the provider (and LiteLLM)
        // meter it — so we don't under-bill large-context requests (#1319). The
        // tier is read from the current catalog (not the historical snapshot):
        // tiers are stable and rare, so this keeps the lookup simple without
        // meaningfully affecting reproducibility.
        $contextTier = ModelCatalog::contextPricing($model->getProviderId());
        if (null !== $contextTier && $promptTokens > $contextTier['threshold_tokens']) {
            $priceIn = $contextTier['price_in_above'];
            $priceOut = $contextTier['price_out_above'];
            $priceSnapshot['price_in'] = number_format($priceIn, 8, '.', '');
            $priceSnapshot['price_out'] = number_format($priceOut, 8, '.', '');
        }

        $pricePerInputToken = $this->convertToPerToken($priceIn, $priceSnapshot['in_unit']);
        $pricePerOutputToken = $this->convertToPerToken($priceOut, $priceSnapshot['out_unit']);

        // Determine cache discount based on provider
        $cacheReadDiscount = $this->getCacheReadDiscount($provider);
        $cacheWriteMultiplier = $this->getCacheWriteMultiplier($provider);

        // Override with explicit cache price if available
        $cacheReadPricePerToken = null !== $cachePriceIn
            ? $this->convertToPerToken((float) $cachePriceIn, $priceSnapshot['in_unit'])
            : $pricePerInputToken * $cacheReadDiscount;

        // Regular (non-cached) input tokens
        $regularInputTokens = $promptTokens - $cachedTokens - $cacheCreationTokens;
        if ($regularInputTokens < 0) {
            $regularInputTokens = 0;
        }

        $regularInputCost = $regularInputTokens * $pricePerInputToken;
        $cachedInputCost = $cachedTokens * $cacheReadPricePerToken;
        $cacheCreationCost = $cacheCreationTokens * $pricePerInputToken * $cacheWriteMultiplier;
        $outputCost = $completionTokens * $pricePerOutputToken;

        $totalInputCost = $regularInputCost + $cachedInputCost + $cacheCreationCost;
        $totalCost = $totalInputCost + $outputCost;

        // Cache savings = what we would have paid at full input price minus what we actually paid for cached tokens
        $cacheSavings = ($cachedTokens * $pricePerInputToken) - $cachedInputCost;
        if ($cacheSavings < 0) {
            $cacheSavings = 0;
        }

        $billedInputTokens = $regularInputTokens + $cachedTokens + $cacheCreationTokens;

        return new CostResult(
            totalCost: number_format($totalCost, 6, '.', ''),
            inputCost: number_format($totalInputCost, 6, '.', ''),
            outputCost: number_format($outputCost, 6, '.', ''),
            cacheSavings: number_format($cacheSavings, 6, '.', ''),
            priceSnapshot: $priceSnapshot,
            billedInputTokens: $billedInputTokens,
        );
    }

    /**
     * @return array{price_in: string, price_out: string, in_unit: string, out_unit: string, cache_price_in: string|null, source: string}
     */
    private function getPriceSnapshot(Model $model, ?int $timestamp): array
    {
        $dateTime = $timestamp ? (new \DateTime())->setTimestamp($timestamp) : new \DateTime();

        // Try price history first
        $historyEntry = $this->priceHistoryRepository->findPriceAtTimestamp($model, $dateTime);

        if ($historyEntry) {
            return [
                'price_in' => $historyEntry->getPriceIn(),
                'price_out' => $historyEntry->getPriceOut(),
                'in_unit' => $historyEntry->getInUnit(),
                'out_unit' => $historyEntry->getOutUnit(),
                'cache_price_in' => $historyEntry->getCachePriceIn(),
                'source' => 'history',
            ];
        }

        // Fallback to current model prices
        $cachePrice = $model->getJson()['cache_read_price_per_1M'] ?? null;

        return [
            'price_in' => number_format($model->getPriceIn(), 8, '.', ''),
            'price_out' => number_format($model->getPriceOut(), 8, '.', ''),
            'in_unit' => $model->getInUnit(),
            'out_unit' => $model->getOutUnit(),
            'cache_price_in' => null !== $cachePrice ? number_format((float) $cachePrice, 8, '.', '') : null,
            'source' => 'model',
        ];
    }

    /**
     * Calculate cost for non-token-based models (TTS, image gen, video gen, transcription).
     *
     * Honours `BMODELS.BINUNIT` / `BMODELS.BOUTUNIT` and converts the catalog
     * price into a "per single billable unit" amount before multiplying by
     * `inputQuantity` / `outputQuantity`. The categories are dispatched off
     * the model's `BTAG`:
     *
     *   tag=text2pic    →  bills `outputQuantity` images using `priceOut`
     *                       interpreted via `outUnit` (perpic, per1M, …).
     *   tag=text2sound  →  bills `inputQuantity` characters using `priceIn`
     *                       interpreted via `inUnit` (per1000chars, per1M, …).
     *   tag=text2vid    →  bills `outputQuantity` seconds using `priceOut`
     *                       interpreted via `outUnit` (persec, …).
     *   anything else   →  zero — token billing is the chat path.
     *
     * Issue #886a / Copilot review on PR #932 + #933: the previous
     * implementation read `priceIn`/`priceOut` directly without converting,
     * so a TTS model authored as `priceIn=0.015 per1000chars` would bill
     * 12 000 chars at $180 (1000× too high), and an OpenAI image model
     * authored at `priceOut=40 per1M` would bill 1 image at $40 (1000× too
     * high in the other direction). Tag-driven dispatch + unit conversion
     * keeps catalog entries in their natural authored units while billing
     * stays correct on every install — including those that don't run
     * `SyncModelPricesCommand` against LiteLLM.
     *
     * @param float       $inputQuantity  Input quantity (characters for TTS, seconds for transcription)
     * @param float       $outputQuantity Output quantity (images for image gen, seconds for video gen)
     * @param string|null $resolution     Optional output resolution (e.g. '720p', '1080p', '4K').
     *                                    When the model defines `json.resolution_prices`, the matching
     *                                    per-second price overrides `priceOut`. Falls back to default
     *                                    pricing when omitted or unknown.
     * @param string|null $quality        Optional image quality tier (low|medium|high, or the legacy
     *                                    standard/hd aliases). Used with $size to pick a per-image price
     *                                    from `json.quality_prices` (e.g. gpt-image, #1315).
     * @param string|null $size           Optional image size (e.g. '1024x1024', '1024x1536'). Combined
     *                                    with $quality to look up the exact per-image tier price.
     */
    public function calculateMediaCost(
        ?int $modelId,
        float $inputQuantity = 0,
        float $outputQuantity = 0,
        ?int $timestamp = null,
        ?string $resolution = null,
        ?string $quality = null,
        ?string $size = null,
    ): CostResult {
        if (!$modelId) {
            return $this->zeroCostResult();
        }

        $model = $this->modelRepository->find($modelId);
        if (!$model) {
            $this->logger->warning('CostCalculation: Model not found for media cost', ['model_id' => $modelId]);

            return $this->zeroCostResult();
        }

        $pricingMode = $model->getJson()['pricing_mode'] ?? 'per_token';

        if ('per_token' === $pricingMode) {
            return $this->zeroCostResult();
        }

        $priceSnapshot = $this->getPriceSnapshot($model, $timestamp);

        // Honour the catalog-authored unit. e.g. OpenAI tts-1 is authored
        // as $0.015 per 1000 chars; we want $0.000015 per char before
        // multiplying by the spoken character count.
        $priceIn = self::normaliseToPerUnit(
            (float) $priceSnapshot['price_in'],
            (string) $priceSnapshot['in_unit'],
        );
        $priceOut = self::normaliseToPerUnit(
            (float) $priceSnapshot['price_out'],
            (string) $priceSnapshot['out_unit'],
        );

        $resolvedResolution = $this->resolveResolution($model, $resolution);
        $resolutionPrice = $this->lookupResolutionPrice($model, $resolvedResolution);
        if (null !== $resolutionPrice) {
            // Resolution prices are authored in the same per-unit shape the
            // ingest catalog uses for video (`persec`), so they need the
            // same normalisation. lookupResolutionPrice returns the raw
            // catalog value; if it's already per-1, this is a no-op.
            $priceOut = self::normaliseToPerUnit(
                $resolutionPrice,
                (string) $priceSnapshot['out_unit'],
            );
            $priceSnapshot['resolution'] = $resolvedResolution;
            $priceSnapshot['price_out_resolution'] = number_format($resolutionPrice, 8, '.', '');
        }

        // Image models (gpt-image) charge a different per-image price per
        // quality × size tier. When the model defines `json.quality_prices`,
        // pick the exact tier so low-quality images aren't overbilled and
        // high-quality ones aren't underbilled (#1315). Overrides the flat
        // priceOut; no-op for models without tiers.
        [$tierPrice, $tierQuality, $tierSize] = $this->lookupImageTierPrice($model, $quality, $size);
        if (null !== $tierPrice) {
            $priceOut = self::normaliseToPerUnit($tierPrice, (string) $priceSnapshot['out_unit']);
            $priceSnapshot['image_quality'] = $tierQuality;
            $priceSnapshot['image_size'] = $tierSize;
            $priceSnapshot['price_out_tier'] = number_format($tierPrice, 8, '.', '');
        }

        $inputCost = $inputQuantity * $priceIn;
        $outputCost = $outputQuantity * $priceOut;
        $totalCost = $inputCost + $outputCost;

        return new CostResult(
            totalCost: number_format($totalCost, 6, '.', ''),
            inputCost: number_format($inputCost, 6, '.', ''),
            outputCost: number_format($outputCost, 6, '.', ''),
            cacheSavings: '0.000000',
            priceSnapshot: $priceSnapshot,
            billedInputTokens: 0,
        );
    }

    /**
     * Convert a catalog price authored in `inUnit` / `outUnit` into a
     * "per single billable unit" price (per 1 character, per 1 image, per
     * 1 second). Unknown units fall through unchanged so existing data
     * stays billable.
     *
     * Public + static because it is a pure, stateless unit conversion and is
     * the single source of truth for it: SyncModelPricesCommand reuses it to
     * compare a DB price against LiteLLM on the same per-unit basis (#1318),
     * so billing and drift detection can never diverge on unit handling.
     */
    public static function normaliseToPerUnit(float $price, string $unit): float
    {
        return match (strtolower($unit)) {
            'per1m', 'per1mchars', 'per1mtokens' => $price / 1_000_000,
            'per1k', 'per1000', 'per1000chars' => $price / 1_000,
            // Time-based media (transcription/video): the billable quantity is
            // always passed in SECONDS, so per-minute / per-hour catalog prices
            // must be converted down to per-second. Authoring in the provider's
            // natural unit keeps the catalog readable ($0.111/hour) while billing
            // stays correct (#1314). Anything already per-second is a no-op.
            'permin' => $price / 60,
            'perhour' => $price / 3_600,
            'per1', 'perchar', 'perpic', 'perimage', 'persec', 'persecond' => $price,
            '-', '', 'free' => 0.0,
            default => $price,
        };
    }

    /**
     * Pick the effective resolution for a model.
     *
     * Priority:
     *   1. Caller-provided resolution (if it appears in `allowed_resolutions`)
     *   2. Model's `default_resolution`
     *   3. null (caller didn't ask, model didn't say -> use flat priceOut)
     */
    private function resolveResolution(Model $model, ?string $resolution): ?string
    {
        $json = $model->getJson();
        $allowed = is_array($json['allowed_resolutions'] ?? null) ? $json['allowed_resolutions'] : [];

        if (null !== $resolution && in_array($resolution, $allowed, true)) {
            return $resolution;
        }

        $default = $json['default_resolution'] ?? null;
        if (is_string($default) && '' !== $default) {
            return $default;
        }

        return null;
    }

    /**
     * Look up the per-unit price for a specific resolution from the model JSON.
     * Returns null when the model does not define resolution-aware pricing.
     */
    private function lookupResolutionPrice(Model $model, ?string $resolution): ?float
    {
        if (null === $resolution) {
            return null;
        }

        $prices = $model->getJson()['resolution_prices'] ?? null;
        if (!is_array($prices) || !isset($prices[$resolution])) {
            return null;
        }

        return (float) $prices[$resolution];
    }

    /**
     * Resolve the per-image price for an image model's quality × size tier.
     *
     * gpt-image bills a different price for every (quality, size) combination
     * (e.g. gpt-image-1 low 1024² = $0.011 but high 1024² = $0.167). The catalog
     * encodes this as `json.quality_prices[quality][size]` with `default_quality`
     * / `default_size` fall-backs. Returns `[price, resolvedQuality, resolvedSize]`,
     * or `[null, null, null]` when the model has no tier table (flat priceOut).
     *
     * @return array{0: float|null, 1: string|null, 2: string|null}
     */
    private function lookupImageTierPrice(Model $model, ?string $quality, ?string $size): array
    {
        $json = $model->getJson();
        $tiers = $json['quality_prices'] ?? null;
        if (!is_array($tiers) || [] === $tiers) {
            return [null, null, null];
        }

        $resolvedQuality = $this->normaliseImageQuality($quality);
        if (null === $resolvedQuality || !isset($tiers[$resolvedQuality]) || !is_array($tiers[$resolvedQuality])) {
            $default = $json['default_quality'] ?? null;
            $resolvedQuality = is_string($default) && isset($tiers[$default])
                ? $default
                : (string) array_key_first($tiers);
        }

        $sizes = $tiers[$resolvedQuality] ?? null;
        if (!is_array($sizes) || [] === $sizes) {
            return [null, null, null];
        }

        $resolvedSize = is_string($size) && isset($sizes[$size]) ? $size : null;
        if (null === $resolvedSize) {
            $defaultSize = $json['default_size'] ?? null;
            $resolvedSize = is_string($defaultSize) && isset($sizes[$defaultSize])
                ? $defaultSize
                : (string) array_key_first($sizes);
        }

        return [(float) $sizes[$resolvedSize], $resolvedQuality, $resolvedSize];
    }

    /**
     * Map the app-level quality value onto OpenAI's low|medium|high tiers.
     *
     * Mirrors the mapping in OpenAIProvider::generateImageWithGptImage1() so the
     * price we bill matches the quality actually requested from the provider:
     * standard→medium, hd→high, low/medium/high pass through, and unknown
     * values map to 'high' because the provider defaults them to 'high' before
     * sending — billing anything cheaper would under-bill the actual request.
     * Only 'auto' (OpenAI picks the tier, we can't know which) and null fall
     * back to null so the caller applies the model's `default_quality`.
     */
    private function normaliseImageQuality(?string $quality): ?string
    {
        if (null === $quality) {
            return null;
        }

        return match (strtolower($quality)) {
            'standard' => 'medium',
            'hd' => 'high',
            'low', 'medium', 'high' => strtolower($quality),
            'auto' => null,
            default => 'high',
        };
    }

    /**
     * Detect whether a model uses non-token-based pricing.
     */
    public function getPricingMode(?int $modelId): string
    {
        if (!$modelId) {
            return 'per_token';
        }

        $model = $this->modelRepository->find($modelId);
        if (!$model) {
            return 'per_token';
        }

        return $model->getJson()['pricing_mode'] ?? 'per_token';
    }

    private function convertToPerToken(float $price, string $unit): float
    {
        return match ($unit) {
            'per1M' => $price / 1_000_000,
            'per1K' => $price / 1_000,
            'per1' => $price,
            default => $price / 1_000_000,
        };
    }

    private function getCacheReadDiscount(string $provider): float
    {
        return match (strtolower($provider)) {
            'anthropic' => self::CACHE_READ_DISCOUNT_ANTHROPIC,
            default => self::CACHE_READ_DISCOUNT_DEFAULT,
        };
    }

    private function getCacheWriteMultiplier(string $provider): float
    {
        return match (strtolower($provider)) {
            'anthropic' => self::CACHE_WRITE_MULTIPLIER_ANTHROPIC,
            default => 1.0,
        };
    }

    private function zeroCostResult(): CostResult
    {
        return new CostResult(
            totalCost: '0.000000',
            inputCost: '0.000000',
            outputCost: '0.000000',
            cacheSavings: '0.000000',
            priceSnapshot: [],
            billedInputTokens: 0,
        );
    }
}
