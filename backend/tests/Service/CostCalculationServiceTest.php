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
        $this->modelRepository->expects(self::any())->method('find')->with(999)->willReturn(null);

        $result = $this->service->calculateCost(100, 50, 0, 0, 999);

        $this->assertSame('0.000000', $result->totalCost);
    }

    public function testCalculateCostWithTokenBasedModel(): void
    {
        $model = $this->createModelMock(1, 'openai', 5.0, 15.0, 'per1M', 'per1M');

        $this->modelRepository->expects(self::any())->method('find')->with(1)->willReturn($model);
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        $result = $this->service->calculateCost(1000, 500, 0, 0, 1);

        $expectedInputCost = 1000 * (5.0 / 1_000_000);
        $expectedOutputCost = 500 * (15.0 / 1_000_000);
        $expectedTotal = $expectedInputCost + $expectedOutputCost;

        $this->assertSame(number_format($expectedTotal, 6, '.', ''), $result->totalCost);
        $this->assertSame(1000, $result->billedInputTokens);
    }

    public function testCalculateCostAppliesLongContextTierAboveThreshold(): void
    {
        // gemini-2.5-pro: base 1.25/10 per 1M, above-200k tier 2.50/15 (#1319).
        $model = $this->createModelMock(20, 'Google', 1.25, 10.0, 'per1M', 'per1M', [], 'gemini-2.5-pro');

        $this->modelRepository->expects(self::any())->method('find')->with(20)->willReturn($model);
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        $promptTokens = 250_000;
        $completionTokens = 1_000;
        $result = $this->service->calculateCost($promptTokens, $completionTokens, 0, 0, 20);

        $expectedInput = $promptTokens * (2.5 / 1_000_000);
        $expectedOutput = $completionTokens * (15.0 / 1_000_000);

        $this->assertSame(number_format($expectedInput, 6, '.', ''), $result->inputCost);
        $this->assertSame(number_format($expectedOutput, 6, '.', ''), $result->outputCost);
        $this->assertSame(number_format($expectedInput + $expectedOutput, 6, '.', ''), $result->totalCost);
    }

    public function testCalculateCostUsesBaseTierBelowThreshold(): void
    {
        $model = $this->createModelMock(21, 'Google', 1.25, 10.0, 'per1M', 'per1M', [], 'gemini-2.5-pro');

        $this->modelRepository->expects(self::any())->method('find')->with(21)->willReturn($model);
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        $promptTokens = 100_000;
        $completionTokens = 1_000;
        $result = $this->service->calculateCost($promptTokens, $completionTokens, 0, 0, 21);

        $expectedInput = $promptTokens * (1.25 / 1_000_000);
        $expectedOutput = $completionTokens * (10.0 / 1_000_000);

        $this->assertSame(number_format($expectedInput, 6, '.', ''), $result->inputCost);
        $this->assertSame(number_format($expectedOutput, 6, '.', ''), $result->outputCost);
    }

    public function testCalculateCostWithoutContextTierIsUnaffectedByHugePrompt(): void
    {
        // A model with no context tier must bill the flat base rate even for a
        // huge prompt — no regression for the vast majority of models (#1319).
        $model = $this->createModelMock(22, 'OpenAI', 5.0, 15.0, 'per1M', 'per1M', [], 'gpt-4o');

        $this->modelRepository->expects(self::any())->method('find')->with(22)->willReturn($model);
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        $promptTokens = 500_000;
        $result = $this->service->calculateCost($promptTokens, 1_000, 0, 0, 22);

        $expectedInput = $promptTokens * (5.0 / 1_000_000);
        $this->assertSame(number_format($expectedInput, 6, '.', ''), $result->inputCost);
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

        $this->modelRepository->expects(self::any())->method('find')->with(2)->willReturn($model);
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

        $this->modelRepository->expects(self::any())->method('find')->with(3)->willReturn($model);
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

        $this->modelRepository->expects(self::any())->method('find')->with(4)->willReturn($model);
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        $durationSeconds = 30.0;
        $result = $this->service->calculateMediaCost(4, $durationSeconds, 0);

        $expectedCost = 30.0 * 0.0001;
        $this->assertSame(number_format($expectedCost, 6, '.', ''), $result->totalCost);
    }

    public function testCalculateMediaCostPerHourTranscription(): void
    {
        // Groq whisper-large-v3: authored $0.111/hour, billed on audio seconds
        // (#1314). RateLimitService passes duration to both in/out quantities.
        $model = $this->createModelMock(7, 'Groq', 0.111, 0.0, 'perhour', '-', ['pricing_mode' => 'per_second']);

        $this->modelRepository->expects(self::any())->method('find')->with(7)->willReturn($model);
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        // A full hour of audio must cost exactly the hourly rate.
        $result = $this->service->calculateMediaCost(7, 3600.0, 3600.0);

        $this->assertSame('0.111000', $result->totalCost);
    }

    public function testCalculateMediaCostPerMinuteTranscription(): void
    {
        // OpenAI whisper-1: authored $0.006/min, billed on audio seconds (#1314).
        $model = $this->createModelMock(8, 'OpenAI', 0.006, 0.0, 'permin', '-', ['pricing_mode' => 'per_second']);

        $this->modelRepository->expects(self::any())->method('find')->with(8)->willReturn($model);
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        // 120 seconds = 2 minutes → 2 × $0.006.
        $result = $this->service->calculateMediaCost(8, 120.0, 120.0);

        $this->assertSame(number_format(2 * 0.006, 6, '.', ''), $result->totalCost);
    }

    /**
     * @return array<string, mixed>
     */
    private function gptImageJson(): array
    {
        return [
            'pricing_mode' => 'per_image',
            'default_quality' => 'medium',
            'default_size' => '1024x1024',
            'quality_prices' => [
                'low' => ['1024x1024' => 0.011, '1024x1536' => 0.016, '1536x1024' => 0.016],
                'medium' => ['1024x1024' => 0.042, '1024x1536' => 0.063, '1536x1024' => 0.063],
                'high' => ['1024x1024' => 0.167, '1024x1536' => 0.25, '1536x1024' => 0.25],
            ],
        ];
    }

    public function testCalculateMediaCostImageHighQualityTier(): void
    {
        // #1315: high-quality 1024² must bill $0.167, not the flat $0.042.
        $model = $this->createModelMock(10, 'OpenAI', 0.0, 0.042, 'perImage', 'perImage', $this->gptImageJson());
        $this->modelRepository->expects(self::any())->method('find')->with(10)->willReturn($model);
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        $result = $this->service->calculateMediaCost(10, 0, 1.0, null, null, 'high', '1024x1024');

        $this->assertSame('0.167000', $result->totalCost);
    }

    public function testCalculateMediaCostImageLowQualityPortraitTier(): void
    {
        // #1315: low-quality portrait must bill $0.016 (4x cheaper than flat).
        $model = $this->createModelMock(11, 'OpenAI', 0.0, 0.042, 'perImage', 'perImage', $this->gptImageJson());
        $this->modelRepository->expects(self::any())->method('find')->with(11)->willReturn($model);
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        $result = $this->service->calculateMediaCost(11, 0, 1.0, null, null, 'low', '1024x1536');

        $this->assertSame('0.016000', $result->totalCost);
    }

    public function testCalculateMediaCostImageStandardAliasMapsToMedium(): void
    {
        // The app's legacy 'standard' quality must map to the medium tier,
        // mirroring OpenAIProvider's quality map.
        $model = $this->createModelMock(12, 'OpenAI', 0.0, 0.042, 'perImage', 'perImage', $this->gptImageJson());
        $this->modelRepository->expects(self::any())->method('find')->with(12)->willReturn($model);
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        $result = $this->service->calculateMediaCost(12, 0, 1.0, null, null, 'standard', '1024x1024');

        $this->assertSame('0.042000', $result->totalCost);
    }

    public function testCalculateMediaCostImageFallsBackToDefaultTierWhenUnspecified(): void
    {
        // No quality/size passed → default_quality (medium) + default_size (1024²).
        $model = $this->createModelMock(13, 'OpenAI', 0.0, 0.042, 'perImage', 'perImage', $this->gptImageJson());
        $this->modelRepository->expects(self::any())->method('find')->with(13)->willReturn($model);
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        $result = $this->service->calculateMediaCost(13, 0, 1.0);

        $this->assertSame('0.042000', $result->totalCost);
    }

    public function testCalculateMediaCostImageUnknownQualityUsesDefault(): void
    {
        // 'auto' (or any unknown) → default_quality medium, so we never bill $0.
        $model = $this->createModelMock(14, 'OpenAI', 0.0, 0.042, 'perImage', 'perImage', $this->gptImageJson());
        $this->modelRepository->expects(self::any())->method('find')->with(14)->willReturn($model);
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        $result = $this->service->calculateMediaCost(14, 0, 2.0, null, null, 'auto', '1024x1024');

        // 2 images × medium 1024² ($0.042).
        $this->assertSame('0.084000', $result->totalCost);
    }

    public function testCalculateMediaCostImageWithoutTierTableUsesFlatPrice(): void
    {
        // A per_image model without quality_prices keeps the flat priceOut even
        // when quality/size are supplied (no regression for DALL·E/TheHive).
        $model = $this->createModelMock(15, 'OpenAI', 0.0, 0.04, 'perImage', 'perImage', ['pricing_mode' => 'per_image']);
        $this->modelRepository->expects(self::any())->method('find')->with(15)->willReturn($model);
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        $result = $this->service->calculateMediaCost(15, 0, 1.0, null, null, 'high', '1024x1024');

        $this->assertSame('0.040000', $result->totalCost);
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

        $this->modelRepository->expects(self::any())->method('find')->with(5)->willReturn($model);

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

        $this->modelRepository->expects(self::any())->method('find')->with(6)->willReturn($model);

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
        string $providerId = '',
    ): Model {
        $model = $this->createMock(Model::class);
        $model->method('getId')->willReturn($id);
        $model->method('getService')->willReturn($service);
        $model->method('getPriceIn')->willReturn($priceIn);
        $model->method('getPriceOut')->willReturn($priceOut);
        $model->method('getInUnit')->willReturn($inUnit);
        $model->method('getOutUnit')->willReturn($outUnit);
        $model->method('getJson')->willReturn($json);
        $model->method('getProviderId')->willReturn($providerId);

        return $model;
    }
}
