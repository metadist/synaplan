<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Usage;

use App\Entity\Model;
use App\Model\ImageTokenUsage;
use App\Repository\ModelPriceHistoryRepository;
use App\Repository\ModelRepository;
use App\Service\Usage\CostCalculationService;
use App\Service\Usage\ImageTokenRates;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * GPT Image requests are billed from the tokens OpenAI reports, at the token
 * rates in the catalog row; the quality × size price is only the fallback.
 */
final class ImageTokenCostTest extends TestCase
{
    private const IMAGE_ID = 451;
    private const WRAPPER_ID = 453;

    /** GPT Image 2.6 rates, USD per 1M tokens. */
    private const META = [
        'image_output_price_per_1M' => 30,
        'image_input_price_per_1M' => 8,
        'image_cached_input_price_per_1M' => 2,
        'text_input_price_per_1M' => 5,
        'text_cached_input_price_per_1M' => 1.25,
    ];

    public function testTheImagesApiUsageIsBilledAtTheTokenRates(): void
    {
        $usage = ImageTokenUsage::fromImagesApi([
            'input_tokens' => 50,
            'input_tokens_details' => ['text_tokens' => 50, 'image_tokens' => 0],
            'output_tokens' => 1756,
            'total_tokens' => 1806,
        ]);
        self::assertNotNull($usage);

        $cost = $this->service()->calculateMediaUsageCost(self::IMAGE_ID, 'per_image', [
            'images' => 1.0,
            'quality' => 'high',
            'size' => '1024x1024',
            'tokens' => $usage->toArray(),
        ]);

        // 50 × $5/1M = 0.00025 in; 1756 × $30/1M = 0.05268 out.
        self::assertSame(['0.052930', '0.000250', '0.052680'], [$cost->totalCost, $cost->inputCost, $cost->outputCost]);
        self::assertSame('per_image_token', $cost->priceSnapshot['pricing_mode']);
        self::assertSame(50, $cost->billedInputTokens);
    }

    public function testTheResponsesApiBillsCachedTokensAndTheModelThatCalledTheTool(): void
    {
        $usage = ImageTokenUsage::fromResponses([
            'usage' => ['input_tokens' => 500, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 50],
            'tool_usage' => ['image_gen' => [
                'input_tokens' => 1120,
                'input_tokens_details' => ['text_tokens' => 120, 'image_tokens' => 1000],
                'cached_tokens_details' => ['cached_text_tokens' => 20, 'cached_image_tokens' => 400],
                'output_tokens' => 1756,
                'total_tokens' => 2876,
            ]],
        ], 'gpt-5.6-terra');
        self::assertNotNull($usage);

        $cost = $this->service()->calculateMediaUsageCost(self::IMAGE_ID, 'per_image', ['images' => 1.0, 'tokens' => $usage->toArray()]);

        // Image tool: 100 × $5 + 20 × $1.25 + 600 × $8 + 400 × $2 = $0.006125 in,
        // 1756 × $30 = $0.05268 out. Wrapper: 500 × $2 = $0.001 in, 50 × $8 = $0.0004 out.
        self::assertSame(['0.060205', '0.007125', '0.053080'], [$cost->totalCost, $cost->inputCost, $cost->outputCost]);
        self::assertSame(self::WRAPPER_ID, $cost->priceSnapshot['wrapper_model_id']);
        self::assertSame(1620, $cost->billedInputTokens);
    }

    public function testAnAnswerWithoutUsageFallsBackToTheQualityAndSizePrice(): void
    {
        $cost = $this->service()->calculateMediaUsageCost(self::IMAGE_ID, 'per_image', ['images' => 1.0, 'quality' => 'high', 'size' => '1024x1024']);

        self::assertSame('0.052680', $cost->totalCost);
        self::assertArrayNotHasKey('image_tokens', $cost->priceSnapshot);
    }

    public function testAModelWithoutTokenRatesKeepsItsPerImagePrice(): void
    {
        self::assertNull(ImageTokenRates::fromModelJson(['pricing_mode' => 'per_image']));
        self::assertNull(ImageTokenRates::fromModelJson(['meta' => ['image_output_price_per_1M' => 0]]));
    }

    public function testMissingCachedRatesBillCachedTokensAtTheFullInputRate(): void
    {
        $rates = ImageTokenRates::fromModelJson(['meta' => ['image_output_price_per_1M' => 30, 'image_input_price_per_1M' => 8, 'text_input_price_per_1M' => 5]]);
        self::assertNotNull($rates);

        self::assertEqualsWithDelta(0.0013, $rates->inputCost(new ImageTokenUsage(100, 100, 100, 100, 0)), 1e-12);
    }

    public function testUsageWithoutAnInputSplitIsNotPriced(): void
    {
        self::assertNull(ImageTokenUsage::fromImagesApi(['input_tokens' => 50, 'output_tokens' => 1756]));
        self::assertNull(ImageTokenUsage::fromResponses(['usage' => ['input_tokens' => 10, 'output_tokens' => 5]], 'gpt-5.6-terra'));
    }

    public function testTheUsageRidesOnTheFirstImageIntoMediaUsage(): void
    {
        $tokens = ['text_in' => 50, 'out' => 1756];

        self::assertSame(['tokens' => $tokens], ImageTokenUsage::mediaUsageOf([['url' => 'data:', 'token_usage' => $tokens], ['url' => 'data:']]));
        self::assertSame([], ImageTokenUsage::mediaUsageOf([['url' => 'data:']]));
        self::assertSame([], ImageTokenUsage::mediaUsageOf(['not-an-image']));
    }

    public function testCachedCountsCannotExceedTheInputTheyArePartOf(): void
    {
        $usage = ImageTokenUsage::fromResponses(['tool_usage' => ['image_gen' => [
            'input_tokens_details' => ['text_tokens' => 10, 'image_tokens' => 0],
            'cached_tokens_details' => ['cached_text_tokens' => 99, 'cached_image_tokens' => 5],
            'output_tokens' => 1,
        ]]], 'gpt-5.6-terra');

        self::assertSame([10, 0], [$usage?->cachedTextIn, $usage?->cachedImageIn]);
        self::assertEquals($usage, ImageTokenUsage::fromArray($usage?->toArray()));
    }

    private function service(): CostCalculationService
    {
        $image = $this->model('OpenAI', 0.0, 0.01317, 'perImage', 'perImage', [
            'pricing_mode' => 'per_image',
            'quality_prices' => ['high' => ['1024x1024' => 0.05268]],
            'meta' => self::META,
        ]);
        $wrapper = $this->model('OpenAI', 2.0, 8.0, 'per1M', 'per1M', []);

        $models = $this->createStub(ModelRepository::class);
        $models->method('find')->willReturnCallback(static fn (int $id): ?Model => match ($id) {
            self::IMAGE_ID => $image,
            self::WRAPPER_ID => $wrapper,
            default => null,
        });
        $models->method('findChatIdByProvider')->willReturnCallback(
            static fn (string $provider, string $name): ?int => 'gpt-5.6-terra' === $name ? self::WRAPPER_ID : null,
        );
        $history = $this->createStub(ModelPriceHistoryRepository::class);
        $history->method('findPriceAtTimestamp')->willReturn(null);

        return new CostCalculationService($models, $history, new NullLogger());
    }

    /**
     * @param array<string, mixed> $json
     */
    private function model(string $service, float $priceIn, float $priceOut, string $inUnit, string $outUnit, array $json): Model
    {
        $model = $this->createStub(Model::class);
        $model->method('getService')->willReturn($service);
        $model->method('getPriceIn')->willReturn($priceIn);
        $model->method('getPriceOut')->willReturn($priceOut);
        $model->method('getInUnit')->willReturn($inUnit);
        $model->method('getOutUnit')->willReturn($outUnit);
        $model->method('getJson')->willReturn($json);
        $model->method('isPriceKnown')->willReturn(true);

        return $model;
    }
}
