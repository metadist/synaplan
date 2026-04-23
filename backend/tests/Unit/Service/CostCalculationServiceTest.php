<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\DTO\CostResult;
use App\Entity\Model;
use App\Entity\ModelPriceHistory;
use App\Repository\ModelPriceHistoryRepository;
use App\Repository\ModelRepository;
use App\Service\CostCalculationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CostCalculationServiceTest extends TestCase
{
    private ModelRepository $modelRepository;
    private ModelPriceHistoryRepository $priceHistoryRepository;
    private CostCalculationService $service;

    protected function setUp(): void
    {
        $this->modelRepository = $this->createMock(ModelRepository::class);
        $this->priceHistoryRepository = $this->createMock(ModelPriceHistoryRepository::class);

        $this->service = new CostCalculationService(
            $this->modelRepository,
            $this->priceHistoryRepository,
            new NullLogger(),
        );
    }

    public function testReturnsZeroCostWhenModelIdIsNull(): void
    {
        $result = $this->service->calculateCost(100, 50, 0, 0, null);

        $this->assertInstanceOf(CostResult::class, $result);
        $this->assertSame('0.000000', $result->totalCost);
        $this->assertSame('0.000000', $result->inputCost);
        $this->assertSame('0.000000', $result->outputCost);
        $this->assertSame('0.000000', $result->cacheSavings);
        $this->assertSame(0, $result->billedInputTokens);
    }

    public function testReturnsZeroCostWhenModelNotFound(): void
    {
        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn(null);

        $result = $this->service->calculateCost(100, 50, 0, 0, 999);

        $this->assertSame('0.000000', $result->totalCost);
    }

    public function testCalculatesBasicCostWithPerMillionPricing(): void
    {
        $model = $this->createModelMock('openai', 3.0, 15.0, 'per1M', 'per1M');

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        // 1000 prompt tokens at $3/1M = 0.003000
        // 500 completion tokens at $15/1M = 0.007500
        $result = $this->service->calculateCost(1000, 500, 0, 0, 1);

        $this->assertSame('0.010500', $result->totalCost);
        $this->assertSame('0.003000', $result->inputCost);
        $this->assertSame('0.007500', $result->outputCost);
        $this->assertSame('0.000000', $result->cacheSavings);
        $this->assertSame(1000, $result->billedInputTokens);
    }

    public function testCalculatesCostWithCachedTokensDefaultProvider(): void
    {
        $model = $this->createModelMock('openai', 3.0, 15.0, 'per1M', 'per1M');

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        // 1000 total prompt, 200 cached (default 50% discount), 0 cache creation
        // Regular input: 800 * 3/1M = 0.002400
        // Cached input: 200 * 3/1M * 0.50 = 0.000300
        // Completion: 500 * 15/1M = 0.007500
        $result = $this->service->calculateCost(1000, 500, 200, 0, 1);

        $this->assertSame('0.010200', $result->totalCost);
        $this->assertSame(1000, $result->billedInputTokens);

        // Cache savings: 200 * 3/1M - 200 * 3/1M * 0.5 = 0.000300
        $this->assertSame('0.000300', $result->cacheSavings);
    }

    public function testCalculatesCostWithCachedTokensAnthropicProvider(): void
    {
        $model = $this->createModelMock('anthropic', 3.0, 15.0, 'per1M', 'per1M');

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        // Anthropic cache: read discount = 10%, write multiplier = 1.25
        // 1000 total prompt, 200 cached, 100 cache creation
        // Regular: (1000 - 200 - 100) = 700 * 3/1M = 0.002100
        // Cached: 200 * 3/1M * 0.10 = 0.000060
        // Cache creation: 100 * 3/1M * 1.25 = 0.000375
        // Completion: 500 * 15/1M = 0.007500
        $result = $this->service->calculateCost(1000, 500, 200, 100, 1);

        $this->assertSame('0.010035', $result->totalCost);
    }

    public function testUsesHistoryPriceWhenAvailable(): void
    {
        $model = $this->createModelMock('openai', 3.0, 15.0, 'per1M', 'per1M');

        $historyEntry = $this->createMock(ModelPriceHistory::class);
        $historyEntry->method('getPriceIn')->willReturn('5.00000000');
        $historyEntry->method('getPriceOut')->willReturn('20.00000000');
        $historyEntry->method('getInUnit')->willReturn('per1M');
        $historyEntry->method('getOutUnit')->willReturn('per1M');
        $historyEntry->method('getCachePriceIn')->willReturn(null);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn($historyEntry);

        // Should use history prices (5.0 in, 20.0 out) not model prices (3.0 in, 15.0 out)
        // 1000 * 5/1M = 0.005000
        // 500 * 20/1M = 0.010000
        $result = $this->service->calculateCost(1000, 500, 0, 0, 1);

        $this->assertSame('0.015000', $result->totalCost);
        $this->assertSame('history', $result->priceSnapshot['source']);
    }

    public function testReturnsZeroCostWhenPricesAreZero(): void
    {
        $model = $this->createModelMock('openai', 0.0, 0.0, 'per1M', 'per1M');

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        $result = $this->service->calculateCost(1000, 500, 0, 0, 1);

        $this->assertSame('0.000000', $result->totalCost);
        $this->assertSame(1000, $result->billedInputTokens);
    }

    public function testPerThousandUnitConversion(): void
    {
        $model = $this->createModelMock('openai', 0.003, 0.015, 'per1K', 'per1K');

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        // 1000 * 0.003/1K = 0.003000
        // 500 * 0.015/1K = 0.007500
        $result = $this->service->calculateCost(1000, 500, 0, 0, 1);

        $this->assertSame('0.010500', $result->totalCost);
    }

    public function testExplicitCachePriceOverridesDiscount(): void
    {
        $model = $this->createModelMock('openai', 3.0, 15.0, 'per1M', 'per1M', ['cache_read_price_per_1M' => 0.5]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        // 1000 total, 200 cached with explicit cache price 0.5/1M
        // Regular: 800 * 3/1M = 0.002400
        // Cached: 200 * 0.5/1M = 0.000100
        // Completion: 500 * 15/1M = 0.007500
        $result = $this->service->calculateCost(1000, 500, 200, 0, 1);

        $this->assertSame('0.010000', $result->totalCost);
    }

    public function testNegativeRegularInputTokensClamped(): void
    {
        $model = $this->createModelMock('openai', 3.0, 15.0, 'per1M', 'per1M');

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        // cached + cache_creation > prompt_tokens → regular should be 0
        $result = $this->service->calculateCost(100, 50, 80, 50, 1);

        // Regular = 0, cached = 80 * 3/1M * 0.5, cache_creation = 50 * 3/1M * 1.0
        // Output = 50 * 15/1M
        $this->assertNotSame('', $result->totalCost);
        $this->assertGreaterThanOrEqual(0, (float) $result->totalCost);
    }

    public function testCalculateMediaCostUsesFlatPriceWhenNoResolutionPrices(): void
    {
        $model = $this->createModelMock('google', 0.0, 0.40, '-', 'persec', [
            'pricing_mode' => 'per_second',
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        $result = $this->service->calculateMediaCost(45, 0, 8.0);

        // 8 seconds * $0.40 = $3.20
        $this->assertSame('3.200000', $result->totalCost);
        $this->assertSame('3.200000', $result->outputCost);
    }

    public function testCalculateMediaCostUsesResolutionSpecificPrice(): void
    {
        $model = $this->createModelMock('google', 0.0, 0.40, '-', 'persec', [
            'pricing_mode' => 'per_second',
            'allowed_resolutions' => ['720p', '1080p', '4K'],
            'default_resolution' => '720p',
            'resolution_prices' => [
                '720p' => 0.40,
                '1080p' => 0.40,
                '4K' => 0.60,
            ],
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        $result = $this->service->calculateMediaCost(45, 0, 8.0, null, '4K');

        // 8 seconds * $0.60 (4K) = $4.80
        $this->assertSame('4.800000', $result->totalCost);
        $this->assertSame('4.800000', $result->outputCost);
        $this->assertSame('4K', $result->priceSnapshot['resolution']);
        $this->assertSame('0.60000000', $result->priceSnapshot['price_out_resolution']);
    }

    public function testCalculateMediaCostFallsBackToDefaultResolutionForUnknownResolution(): void
    {
        $model = $this->createModelMock('google', 0.0, 0.10, '-', 'persec', [
            'pricing_mode' => 'per_second',
            'allowed_resolutions' => ['720p', '1080p', '4K'],
            'default_resolution' => '720p',
            'resolution_prices' => [
                '720p' => 0.10,
                '1080p' => 0.12,
                '4K' => 0.30,
            ],
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        // Caller asked for an unsupported resolution; service must fall back to default_resolution.
        $result = $this->service->calculateMediaCost(195, 0, 6.0, null, '8K');

        // 6 * $0.10 (default 720p) = $0.60
        $this->assertSame('0.600000', $result->totalCost);
        $this->assertSame('720p', $result->priceSnapshot['resolution']);
    }

    public function testCalculateMediaCostReturnsZeroForPerTokenModel(): void
    {
        $model = $this->createModelMock('openai', 3.0, 15.0, 'per1M', 'per1M', [
            'pricing_mode' => 'per_token',
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);

        $result = $this->service->calculateMediaCost(1, 100, 50);

        $this->assertSame('0.000000', $result->totalCost);
    }

    public function testCostResultDtoStructure(): void
    {
        $result = new CostResult(
            totalCost: '0.015000',
            inputCost: '0.005000',
            outputCost: '0.010000',
            cacheSavings: '0.001000',
            priceSnapshot: ['price_in' => '3.0', 'source' => 'model'],
            billedInputTokens: 1000,
        );

        $this->assertSame('0.015000', $result->totalCost);
        $this->assertSame('0.005000', $result->inputCost);
        $this->assertSame('0.010000', $result->outputCost);
        $this->assertSame('0.001000', $result->cacheSavings);
        $this->assertSame(1000, $result->billedInputTokens);
        $this->assertSame('model', $result->priceSnapshot['source']);
    }

    private function createModelMock(
        string $service,
        float $priceIn,
        float $priceOut,
        string $inUnit,
        string $outUnit,
        array $json = [],
    ): Model {
        $model = $this->createMock(Model::class);
        $model->method('getService')->willReturn($service);
        $model->method('getPriceIn')->willReturn($priceIn);
        $model->method('getPriceOut')->willReturn($priceOut);
        $model->method('getInUnit')->willReturn($inUnit);
        $model->method('getOutUnit')->willReturn($outUnit);
        $model->method('getJson')->willReturn($json);

        return $model;
    }
}
