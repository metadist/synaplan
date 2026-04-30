<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\CostResult;
use App\Entity\Model;
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
     * @param float       $inputQuantity  Input quantity (characters for TTS, seconds for transcription)
     * @param float       $outputQuantity Output quantity (images for image gen, seconds for video gen)
     * @param string|null $resolution     Optional output resolution (e.g. '720p', '1080p', '4K').
     *                                    When the model defines `json.resolution_prices`, the matching
     *                                    per-second price overrides `priceOut`. Falls back to default
     *                                    pricing when omitted or unknown.
     */
    public function calculateMediaCost(
        ?int $modelId,
        float $inputQuantity = 0,
        float $outputQuantity = 0,
        ?int $timestamp = null,
        ?string $resolution = null,
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
        $priceIn = (float) $priceSnapshot['price_in'];
        $priceOut = (float) $priceSnapshot['price_out'];

        $resolvedResolution = $this->resolveResolution($model, $resolution);
        $resolutionPrice = $this->lookupResolutionPrice($model, $resolvedResolution);
        if (null !== $resolutionPrice) {
            $priceOut = $resolutionPrice;
            $priceSnapshot['resolution'] = $resolvedResolution;
            $priceSnapshot['price_out_resolution'] = number_format($resolutionPrice, 8, '.', '');
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
        return match ($provider) {
            'anthropic' => self::CACHE_READ_DISCOUNT_ANTHROPIC,
            default => self::CACHE_READ_DISCOUNT_DEFAULT,
        };
    }

    private function getCacheWriteMultiplier(string $provider): float
    {
        return match ($provider) {
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
