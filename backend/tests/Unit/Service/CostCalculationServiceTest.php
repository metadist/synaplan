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
        $model = $this->createModelMock('Anthropic', 3.0, 15.0, 'per1M', 'per1M');

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

    /**
     * Anthropic's 1-hour cache TTL (opt-in via `cache_control: {"ttl": "1h"}`) writes
     * cost 2x base input, not the 1.25x default for the 5-minute TTL — see
     * https://platform.claude.com/docs/en/build-with-claude/prompt-caching. Before
     * this was fixed, ALL cache-creation tokens were billed at the flat 1.25x rate
     * regardless of TTL, under-billing every 1h-TTL write.
     */
    public function testCalculatesCostWithFullyOneHourCacheWriteAnthropicProvider(): void
    {
        $model = $this->createModelMock('Anthropic', 3.0, 15.0, 'per1M', 'per1M');

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        // 1000 total prompt, 200 cached (0.10x read), 100 cache creation ALL 1h TTL (2.0x write)
        // Regular: (1000 - 200 - 100) = 700 * 3/1M = 0.002100
        // Cached: 200 * 3/1M * 0.10 = 0.000060
        // Cache creation (1h): 100 * 3/1M * 2.0 = 0.000600
        // Completion: 500 * 15/1M = 0.007500
        $result = $this->service->calculateCost(1000, 500, 200, 100, 1, null, 100);

        $this->assertSame('0.010260', $result->totalCost);
    }

    /**
     * Mixed TTL: a single request can carry both a 5-minute-TTL breakpoint and a
     * 1-hour-TTL breakpoint (Anthropic's `cache_creation.ephemeral_5m_input_tokens`
     * / `ephemeral_1h_input_tokens`), so the two slices must be billed separately.
     */
    public function testCalculatesCostWithMixedFiveMinuteAndOneHourCacheWriteAnthropicProvider(): void
    {
        $model = $this->createModelMock('Anthropic', 3.0, 15.0, 'per1M', 'per1M');

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        // 1000 total prompt, 0 cached, 100 cache creation: 40 at 1h TTL, 60 at 5m TTL (remainder)
        // Regular: (1000 - 0 - 100) = 900 * 3/1M = 0.002700
        // Cache creation (5m): 60 * 3/1M * 1.25 = 0.000225
        // Cache creation (1h): 40 * 3/1M * 2.0 = 0.000240
        // Completion: 500 * 15/1M = 0.007500
        $result = $this->service->calculateCost(1000, 500, 0, 100, 1, null, 40);

        $this->assertSame('0.010665', $result->totalCost);
    }

    /**
     * Non-Anthropic providers never carry a 1h/5m TTL distinction; a stray
     * $cacheCreation1hTokens value (e.g. from a shared code path) must not
     * change their (flat 1.0x) cache-write cost.
     */
    public function testCacheCreation1hTokensIgnoredForNonAnthropicProvider(): void
    {
        $model = $this->createModelMock('openai', 3.0, 15.0, 'per1M', 'per1M');

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        $withoutFlag = $this->service->calculateCost(1000, 500, 0, 100, 1);
        $withFlag = $this->service->calculateCost(1000, 500, 0, 100, 1, null, 100);

        $this->assertSame($withoutFlag->totalCost, $withFlag->totalCost);
    }

    /**
     * Defensive clamp: a caller-supplied 1h count above the total cache-creation
     * total (e.g. a stale/inconsistent breakdown) must never inflate the bill
     * beyond "the whole cache-creation total billed at the 1h rate".
     */
    public function testCacheCreation1hTokensClampedToCacheCreationTokens(): void
    {
        $model = $this->createModelMock('Anthropic', 3.0, 15.0, 'per1M', 'per1M');

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        $clampedToTotal = $this->service->calculateCost(1000, 500, 0, 100, 1, null, 100);
        $overTotal = $this->service->calculateCost(1000, 500, 0, 100, 1, null, 999);

        $this->assertSame($clampedToTotal->totalCost, $overTotal->totalCost);
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
            'default_resolution' => '1080p',
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

        // 6 * $0.12 (default 1080p) = $0.72
        $this->assertSame('0.720000', $result->totalCost);
        $this->assertSame('1080p', $result->priceSnapshot['resolution']);
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

    /**
     * #1317: Higgsfield bills a flat credit amount per clip, so a per_generation
     * model charges its authored price once per generation — independent of the
     * clip duration passed in.
     */
    public function testCalculateMediaCostChargesFlatPricePerGeneration(): void
    {
        $model = $this->createModelMock('Higgsfield', 0.0, 2.50, '-', 'per_generation', [
            'pricing_mode' => 'per_generation',
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        // One generation (one clip) → flat $2.50 regardless of duration.
        $result = $this->service->calculateMediaCost(302, 0, 1.0);

        $this->assertSame('2.500000', $result->totalCost);
        $this->assertSame('2.500000', $result->outputCost);
    }

    public function testCalculateMediaCostPerGenerationScalesWithClipCount(): void
    {
        $model = $this->createModelMock('Higgsfield', 0.0, 1.75, '-', 'per_generation', [
            'pricing_mode' => 'per_generation',
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        // A hypothetical 2-clip batch bills 2 × the flat per-clip price.
        $result = $this->service->calculateMediaCost(308, 0, 2.0);

        $this->assertSame('3.500000', $result->totalCost);
    }

    /**
     * Regression test for issue #886a: image generation models had no
     * `pricing_mode` set in the catalog, so MediaGenerationHandler's
     * `media_usage['images']` was ignored by `recordUsage()` and cost
     * silently fell through to the per-token path with zero billed tokens.
     *
     * With `pricing_mode: per_image` on the catalog entry, `media_usage`
     * goes through the per-image path. The TheHive entries already author
     * their price in `perpic` units, so the natural multiplication holds.
     */
    public function testCalculateMediaCostBillsPerImageWhenPricingModeIsPerImage(): void
    {
        $model = $this->createModelMock('thehive', 0.0, 0.05, '-', 'perpic', [
            'pricing_mode' => 'per_image',
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        // Caller signals "we generated 4 images" via outputQuantity. The
        // input quantity (e.g. 1200 prompt characters) MUST NOT add cost.
        $result = $this->service->calculateMediaCost(1, 1200, 4.0);

        // 4 * $0.05 = $0.20 — input is ignored (the `-` inUnit normalises to 0).
        $this->assertSame('0.200000', $result->totalCost);
        $this->assertSame('0.000000', $result->inputCost);
        $this->assertSame('0.200000', $result->outputCost);
    }

    /**
     * Imagen 4.0 production shape: priceIn=0, priceOut=0.04, units=perImage.
     * 5 images × $0.04 = $0.20. The unit normaliser must NOT divide
     * `perImage` by 1M — it is already dollars per single image.
     */
    public function testCalculateMediaCostHonoursPerImageOutUnit(): void
    {
        $model = $this->createModelMock('google', 0.0, 0.04, 'perImage', 'perImage', [
            'pricing_mode' => 'per_image',
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        $result = $this->service->calculateMediaCost(1, 0, 5);

        $this->assertSame('0.200000', $result->totalCost);
        $this->assertSame('0.200000', $result->outputCost);
        $this->assertSame('0.000000', $result->inputCost);
    }

    /**
     * Regression test for issue #886b: TTS catalog entries had no
     * `pricing_mode` set, so even though MediaGenerationHandler correctly
     * passed `media_usage['characters']`, the cost path fell through to
     * per-token and recorded $0.000000 in BUSELOG. With
     * `pricing_mode: per_character` the inputQuantity (characters spoken)
     * is billed at priceIn.
     */
    public function testCalculateMediaCostBillsPerCharacterWhenPricingModeIsPerCharacter(): void
    {
        // Mirrors the live BMODELS BID 41 (OpenAI tts-1) shape:
        // priceIn=0.000015 with inUnit=perChar — already per-single-char.
        $model = $this->createModelMock('openai', 0.000015, 0.0, 'perChar', 'perChar', [
            'pricing_mode' => 'per_character',
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        // 12 000 characters spoken.
        $result = $this->service->calculateMediaCost(45, 12000.0, 0.0);

        // 12 000 * 0.000015 = $0.18 — input billed, output ignored.
        $this->assertSame('0.180000', $result->totalCost);
        $this->assertSame('0.180000', $result->inputCost);
        $this->assertSame('0.000000', $result->outputCost);
    }

    /**
     * Defensive case for the unit normaliser: even if a future catalog
     * entry mistakenly leaves `outUnit=per1M` while flipping the
     * `pricing_mode` flag, the calculator must NOT bill $40/image. The
     * normaliser interprets `per1M` as "$X per million units", so 1
     * image × ($40 / 1_000_000) = $0.00004. Tiny, but not catastrophic
     * (Copilot review on PR #932 flagged the original $40-per-image
     * regression caused by the missing unit conversion).
     */
    public function testCalculateMediaCostNormalisesPer1MOutUnitForPerImage(): void
    {
        $model = $this->createModelMock('openai', 5.0, 40.0, 'per1M', 'per1M', [
            'pricing_mode' => 'per_image',
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        $result = $this->service->calculateMediaCost(1, 0, 1);

        // 1 × (40 / 1_000_000) = $0.00004. Non-catastrophic.
        $this->assertSame('0.000040', $result->outputCost);
    }

    public function testCalculateMediaCostHonoursPerPicOutUnit(): void
    {
        // TheHive flux-schnell prod shape: priceOut=0.01, outUnit=perpic.
        // The calculator must NOT divide by 1M — the unit is already
        // dollars per image.
        $model = $this->createModelMock('thehive', 0.0, 0.01, '-', 'perpic', [
            'pricing_mode' => 'per_image',
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        $result = $this->service->calculateMediaCost(1, 0, 5);

        // 5 images × $0.01 = $0.05.
        $this->assertSame('0.050000', $result->totalCost);
    }

    /**
     * Defensive case for the unit normaliser: even if a future catalog
     * entry mistakenly leaves `inUnit=per1000chars` while flipping the
     * `pricing_mode` flag to per_character, the calculator must convert
     * to the per-single-char unit before multiplying. Without
     * normalisation 12 000 chars at $0.015/per1000chars would bill $180
     * (1000× too high — Copilot review on PR #933 flagged this).
     */
    public function testCalculateMediaCostNormalisesPer1000charsForPerCharacterBilling(): void
    {
        $model = $this->createModelMock('openai', 0.015, 0.0, 'per1000chars', '-', [
            'pricing_mode' => 'per_character',
        ]);

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        $result = $this->service->calculateMediaCost(45, 12000.0, 0.0);

        // 12 000 * (0.015 / 1000) = $0.18 — same outcome as the canonical
        // perChar shape above, despite the catalog being authored in
        // per-1000-char units.
        $this->assertSame('0.180000', $result->totalCost);
    }

    // ==================== xAI (GROK) PRICING ====================

    public function testGrokChargesTheStandardTierBelowTheLongContextThreshold(): void
    {
        $model = $this->createModelMock('xAI', 2.0, 6.0, 'per1M', 'per1M', [], 'grok-4.5');

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        // Exactly at the threshold the standard rate still applies.
        // 200000 * 2/1M = 0.400000, 1000 * 6/1M = 0.006000
        $result = $this->service->calculateCost(200000, 1000, 0, 0, 313);

        $this->assertSame('0.406000', $result->totalCost);
        $this->assertSame('2.00000000', $result->priceSnapshot['price_in']);
    }

    public function testGrokSwitchesToTheLongContextTierAboveTwoHundredThousandTokens(): void
    {
        $model = $this->createModelMock('xAI', 2.0, 6.0, 'per1M', 'per1M', [], 'grok-4.5');

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        // One token past the threshold doubles both rates.
        // 200001 * 4/1M = 0.800004, 1000 * 12/1M = 0.012000
        $result = $this->service->calculateCost(200001, 1000, 0, 0, 313);

        $this->assertSame('0.812004', $result->totalCost);
        $this->assertSame('4.00000000', $result->priceSnapshot['price_in']);
        $this->assertSame('12.00000000', $result->priceSnapshot['price_out']);
    }

    public function testGrokBillsCachedPromptTokensAtTheCatalogCacheRate(): void
    {
        $model = $this->createModelMock('xAI', 2.0, 6.0, 'per1M', 'per1M', [
            'cache_read_price_per_1M' => 0.30,
        ], 'grok-4.5');

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        // 100000 prompt tokens, 80000 of them cached:
        //   regular 20000 * 2/1M    = 0.040000
        //   cached  80000 * 0.30/1M = 0.024000
        //   output   1000 * 6/1M    = 0.006000
        $result = $this->service->calculateCost(100000, 1000, 80000, 0, 313);

        $this->assertSame('0.070000', $result->totalCost);
        $this->assertSame('0.064000', $result->inputCost);
        // Full price would have been 0.200000 + 0.006000 → 0.136000 saved.
        $this->assertSame('0.136000', $result->cacheSavings);
    }

    /**
     * OpenAI bills long context at "2x input and cache rates", so a cached
     * token above the threshold costs $2/1M on Astra, not the $1 short-context
     * rate. Before `cache_price_in_above` existed the tier lifted input and
     * output but left cache reads at the cheap rate, under-billing them 2x.
     */
    public function testAstraBillsCachedTokensAtTheLongContextCacheRate(): void
    {
        $model = $this->createModelMock('OpenAI', 10.0, 50.0, 'per1M', 'per1M', [
            'cache_read_price_per_1M' => 1.00,
        ], 'gpt-6-astra');

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        // 300000 prompt tokens (past the 272k threshold), 200000 of them cached:
        //   regular 100000 * 20/1M = 2.000000
        //   cached  200000 *  2/1M = 0.400000
        //   output    1000 * 75/1M = 0.075000
        $result = $this->service->calculateCost(300000, 1000, 200000, 0, 340);

        $this->assertSame('2.475000', $result->totalCost);
        $this->assertSame('2.400000', $result->inputCost);
        $this->assertSame('2.00000000', $result->priceSnapshot['cache_price_in']);
    }

    public function testAstraKeepsTheShortContextCacheRateBelowTheThreshold(): void
    {
        $model = $this->createModelMock('OpenAI', 10.0, 50.0, 'per1M', 'per1M', [
            'cache_read_price_per_1M' => 1.00,
        ], 'gpt-6-astra');

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        // 100000 prompt tokens, 80000 cached:
        //   regular 20000 * 10/1M = 0.200000
        //   cached  80000 *  1/1M = 0.080000
        //   output   1000 * 50/1M = 0.050000
        $result = $this->service->calculateCost(100000, 1000, 80000, 0, 340);

        $this->assertSame('0.330000', $result->totalCost);
        $this->assertSame('1.00000000', $result->priceSnapshot['cache_price_in']);
    }

    /**
     * GPT-5.6 and later are the first OpenAI families billed for cache WRITES,
     * at 1.25x the uncached input rate. The multiplier used to be Anthropic-only,
     * so written tokens went out at 1.0x.
     */
    public function testAstraBillsCacheWritesAtTheAuthoredMultiplier(): void
    {
        $model = $this->createModelMock('OpenAI', 10.0, 50.0, 'per1M', 'per1M', [
            'cache_read_price_per_1M' => 1.00,
            'cache_write_multiplier' => 1.25,
        ], 'gpt-6-astra');

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        // 100000 prompt tokens, 40000 written to cache, 20000 read from it:
        //   regular 40000 * 10/1M        = 0.400000
        //   cached  20000 *  1/1M        = 0.020000
        //   writes  40000 * 10/1M * 1.25 = 0.500000
        //   output   1000 * 50/1M        = 0.050000
        $result = $this->service->calculateCost(100000, 1000, 20000, 40000, 340);

        $this->assertSame('0.970000', $result->totalCost);
        $this->assertSame('0.920000', $result->inputCost);
    }

    /**
     * The flip side: GPT-5.5 and earlier incur "no additional cache-write
     * charge", so those rows author no multiplier and their written tokens must
     * stay at 1.0x. A provider-wide OpenAI multiplier would over-bill them.
     */
    public function testGpt55BillsCacheWritesAtPlainInputRate(): void
    {
        $model = $this->createModelMock('OpenAI', 5.0, 30.0, 'per1M', 'per1M', [
            'cache_read_price_per_1M' => 0.50,
        ], 'gpt-5.5');

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        // 100000 prompt tokens, 40000 written to cache:
        //   regular 60000 * 5/1M  = 0.300000
        //   writes  40000 * 5/1M  = 0.200000  (no uplift)
        //   output   1000 * 30/1M = 0.030000
        $result = $this->service->calculateCost(100000, 1000, 0, 40000, 204);

        $this->assertSame('0.530000', $result->totalCost);
        $this->assertSame('0.500000', $result->inputCost);
    }

    public function testGrokImagineImageCostsTwoCentsPerImage(): void
    {
        $model = $this->createModelMock('xAI', 0.0, 0.02, '-', 'perpic', [
            'pricing_mode' => 'per_image',
            'mode_prices' => ['output_cost_per_image' => 0.02],
        ], 'grok-imagine-image');

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn($model);
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        $result = $this->service->calculateMediaCost(316, 0, 1);

        $this->assertSame('0.020000', $result->totalCost);
        $this->assertSame('0.000000', $result->inputCost);
    }

    /**
     * The image path never passes a resolution, so the price has to come from
     * the catalog's `default_resolution` — the same value XaiProvider sends.
     */
    public function testGrokImagineImageProBillsTheCatalogDefaultResolution(): void
    {
        $json = [
            'pricing_mode' => 'per_image',
            'mode_prices' => ['output_cost_per_image' => 0.05],
            'allowed_resolutions' => ['1k', '2k'],
            'default_resolution' => '1k',
            'resolution_prices' => ['1k' => 0.05, '2k' => 0.07],
        ];

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn(
            $this->createModelMock('xAI', 0.0, 0.05, '-', 'perpic', $json, 'grok-imagine-image-quality'),
        );
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        $default = $this->service->calculateMediaCost(318, 0, 1);
        $this->assertSame('0.050000', $default->totalCost);
        $this->assertSame('1k', $default->priceSnapshot['resolution']);

        // An operator switching the row to 2k bills the 2k rate.
        $this->assertSame('0.070000', $this->service->calculateMediaCost(318, 0, 1, null, '2k')->totalCost);
    }

    public function testGrokImagineVideo15BillsTheHigherResolutionTiers(): void
    {
        $json = [
            'pricing_mode' => 'per_second',
            'allowed_resolutions' => ['480p', '720p', '1080p'],
            'default_resolution' => '720p',
            'resolution_prices' => ['480p' => 0.08, '720p' => 0.14, '1080p' => 0.25],
        ];

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn(
            $this->createModelMock('xAI', 0.0, 0.14, '-', 'persec', $json, 'grok-imagine-video-1.5'),
        );
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        // Default 720p render: 8s * $0.14 = $1.12
        $this->assertSame('1.120000', $this->service->calculateMediaCost(319, 0, 8.0, null, '720p')->totalCost);
        // 1080p is the expensive tier: 8s * $0.25 = $2.00
        $this->assertSame('2.000000', $this->service->calculateMediaCost(319, 0, 8.0, null, '1080p')->totalCost);
        // 480p: 4s * $0.08 = $0.32
        $this->assertSame('0.320000', $this->service->calculateMediaCost(319, 0, 4.0, null, '480p')->totalCost);
    }

    public function testGrokImagineVideoBillsPerSecondAtTheRequestedResolution(): void
    {
        $json = [
            'pricing_mode' => 'per_second',
            'allowed_resolutions' => ['480p', '720p'],
            'default_resolution' => '720p',
            'resolution_prices' => ['480p' => 0.05, '720p' => 0.07],
        ];

        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn(
            $this->createModelMock('xAI', 0.0, 0.07, '-', 'persec', $json, 'grok-imagine-video'),
        );
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        // Default 720p render: 8s * $0.07 = $0.56
        $default = $this->service->calculateMediaCost(317, 0, 8.0, null, '720p');
        $this->assertSame('0.560000', $default->totalCost);
        $this->assertSame('720p', $default->priceSnapshot['resolution']);

        // The cheap test render: 4s at 480p * $0.05 = $0.20
        $cheap = $this->service->calculateMediaCost(317, 0, 4.0, null, '480p');
        $this->assertSame('0.200000', $cheap->totalCost);
        $this->assertSame('0.05000000', $cheap->priceSnapshot['price_out_resolution']);
    }

    public function testGrokTtsBillsPerCharacterOfInputText(): void
    {
        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn(
            $this->createModelMock('xAI', 0.000015, 0.0, 'perChar', 'perChar', [
                'pricing_mode' => 'per_character',
            ], 'grok-tts'),
        );
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        // $15/1M chars → 2 000 characters * 0.000015 = $0.03. Output is ignored.
        $result = $this->service->calculateMediaCost(320, 2000.0, 0.0);

        $this->assertSame('0.030000', $result->totalCost);
        $this->assertSame('0.030000', $result->inputCost);
        $this->assertSame('0.000000', $result->outputCost);
    }

    /**
     * The row is authored in $/hour, so the calculator must normalise to
     * per-second before multiplying the clip length — otherwise a 90-second
     * transcription would bill 3 600× too much.
     */
    public function testGrokSttBillsPerSecondFromAnHourlyRate(): void
    {
        // @phpstan-ignore-next-line
        $this->modelRepository->method('find')->willReturn(
            $this->createModelMock('xAI', 0.10, 0.0, 'perhour', '-', [
                'pricing_mode' => 'per_second',
            ], 'grok-stt'),
        );
        // @phpstan-ignore-next-line
        $this->priceHistoryRepository->method('findPriceAtTimestamp')->willReturn(null);

        // 1 hour of audio costs exactly the hourly rate.
        $this->assertSame('0.100000', $this->service->calculateMediaCost(321, 3600.0, 0.0)->totalCost);
        // 90 s * ($0.10 / 3600) = $0.0025
        $this->assertSame('0.002500', $this->service->calculateMediaCost(321, 90.0, 0.0)->totalCost);
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
        string $providerId = '',
    ): Model {
        $model = $this->createMock(Model::class);
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
