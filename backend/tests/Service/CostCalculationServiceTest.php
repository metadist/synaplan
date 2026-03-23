<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Model;
use App\Repository\ModelPriceHistoryRepository;
use App\Repository\ModelRepository;
use App\Service\CostCalculationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CostCalculationServiceTest extends TestCase
{
    private ModelRepository&MockObject $modelRepository;
    private ModelPriceHistoryRepository&MockObject $priceHistoryRepository;
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

    public function testCalculateCostWithNoModelReturnsZero(): void
    {
        $result = $this->service->calculateCost(100, 50, 0, 0, null);

        $this->assertSame('0.000000', $result->totalCost);
        $this->assertSame('0.000000', $result->inputCost);
        $this->assertSame('0.000000', $result->outputCost);
        $this->assertSame(0, $result->billedInputTokens);
    }

    public function testCalculateCostWithMissingModelReturnsZero(): void
    {
        $this->modelRepository->method('find')->with(999)->willReturn(null);

        $result = $this->service->calculateCost(100, 50, 0, 0, 999);

        $this->assertSame('0.000000', $result->totalCost);
    }

    public function testCalculateCostWithTokenBasedModel(): void
    {
        $model = $this->createModelMock(1, 'openai', 5.0, 15.0, 'per1M', 'per1M');

        $this->modelRepository->method('find')->with(1)->willReturn($model);
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        $result = $this->service->calculateCost(1000, 500, 0, 0, 1);

        $expectedInputCost = 1000 * (5.0 / 1_000_000);
        $expectedOutputCost = 500 * (15.0 / 1_000_000);
        $expectedTotal = $expectedInputCost + $expectedOutputCost;

        $this->assertSame(number_format($expectedTotal, 6, '.', ''), $result->totalCost);
        $this->assertSame(1000, $result->billedInputTokens);
    }

    public function testCalculateMediaCostWithNoModelReturnsZero(): void
    {
        $result = $this->service->calculateMediaCost(null, 1000, 0);

        $this->assertSame('0.000000', $result->totalCost);
    }

    public function testCalculateMediaCostPerCharacter(): void
    {
        $model = $this->createModelMock(
            2,
            'openai',
            0.000015,
            0.0,
            'perChar',
            'perChar',
            ['pricing_mode' => 'per_character'],
        );

        $this->modelRepository->method('find')->with(2)->willReturn($model);
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        $characters = 500;
        $result = $this->service->calculateMediaCost(2, (float) $characters, 0);

        $expectedCost = $characters * 0.000015;
        $this->assertSame(number_format($expectedCost, 6, '.', ''), $result->totalCost);
        $this->assertSame(number_format($expectedCost, 6, '.', ''), $result->inputCost);
        $this->assertSame('0.000000', $result->outputCost);
        $this->assertSame(0, $result->billedInputTokens);
    }

    public function testCalculateMediaCostPerImage(): void
    {
        $model = $this->createModelMock(
            3,
            'openai',
            0.0,
            0.04,
            'perImage',
            'perImage',
            ['pricing_mode' => 'per_image'],
        );

        $this->modelRepository->method('find')->with(3)->willReturn($model);
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        $result = $this->service->calculateMediaCost(3, 0, 2.0);

        $expectedCost = 2 * 0.04;
        $this->assertSame(number_format($expectedCost, 6, '.', ''), $result->totalCost);
        $this->assertSame('0.000000', $result->inputCost);
        $this->assertSame(number_format($expectedCost, 6, '.', ''), $result->outputCost);
    }

    public function testCalculateMediaCostPerSecond(): void
    {
        $model = $this->createModelMock(
            4,
            'openai',
            0.0001,
            0.0,
            'perSec',
            'perSec',
            ['pricing_mode' => 'per_second'],
        );

        $this->modelRepository->method('find')->with(4)->willReturn($model);
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        $durationSeconds = 30.0;
        $result = $this->service->calculateMediaCost(4, $durationSeconds, 0);

        $expectedCost = 30.0 * 0.0001;
        $this->assertSame(number_format($expectedCost, 6, '.', ''), $result->totalCost);
    }

    public function testCalculateMediaCostReturnsZeroForTokenBasedModel(): void
    {
        $model = $this->createModelMock(
            5,
            'openai',
            5.0,
            15.0,
            'per1M',
            'per1M',
            ['pricing_mode' => 'per_token'],
        );

        $this->modelRepository->method('find')->with(5)->willReturn($model);

        $result = $this->service->calculateMediaCost(5, 1000, 0);

        $this->assertSame('0.000000', $result->totalCost);
    }

    public function testGetPricingModeReturnsDefaultForMissingModel(): void
    {
        $this->modelRepository->method('find')->willReturn(null);

        $this->assertSame('per_token', $this->service->getPricingMode(999));
    }

    public function testGetPricingModeReturnsDefaultForNull(): void
    {
        $this->assertSame('per_token', $this->service->getPricingMode(null));
    }

    public function testGetPricingModeReturnsModelMode(): void
    {
        $model = $this->createModelMock(
            6,
            'openai',
            0.000015,
            0.0,
            'perChar',
            'perChar',
            ['pricing_mode' => 'per_character'],
        );

        $this->modelRepository->method('find')->with(6)->willReturn($model);

        $this->assertSame('per_character', $this->service->getPricingMode(6));
    }

    /**
     * @param array<string, mixed> $json
     */
    private function createModelMock(
        int $id,
        string $service,
        float $priceIn,
        float $priceOut,
        string $inUnit = 'per1M',
        string $outUnit = 'per1M',
        array $json = [],
    ): Model {
        $model = $this->createMock(Model::class);
        $model->method('getId')->willReturn($id);
        $model->method('getService')->willReturn($service);
        $model->method('getPriceIn')->willReturn($priceIn);
        $model->method('getPriceOut')->willReturn($priceOut);
        $model->method('getInUnit')->willReturn($inUnit);
        $model->method('getOutUnit')->willReturn($outUnit);
        $model->method('getJson')->willReturn($json);

        return $model;
    }
}
