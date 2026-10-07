<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Import;

use App\AI\Import\ListedModelPrice;
use PHPUnit\Framework\TestCase;

final class ListedModelPriceTest extends TestCase
{
    public function testOpenRouterPerTokenStringsBecomeUsdPerMillion(): void
    {
        $price = ListedModelPrice::fromListingItem([
            'id' => 'openai/gpt-4o',
            'pricing' => [
                'prompt' => '0.00000015',
                'completion' => '0.0000006',
            ],
        ]);

        self::assertTrue($price->known);
        self::assertEqualsWithDelta(0.15, $price->priceInPerMillion, 0.0000001);
        self::assertEqualsWithDelta(0.6, $price->priceOutPerMillion, 0.0000001);
    }

    public function testPublishedZeroIsFreeNotUnknown(): void
    {
        $price = ListedModelPrice::fromListingItem([
            'id' => 'meta/llama-free',
            'pricing' => ['prompt' => '0', 'completion' => '0'],
        ]);

        self::assertTrue($price->known);
        self::assertSame(0.0, $price->priceInPerMillion);
        self::assertSame(0.0, $price->priceOutPerMillion);
    }

    public function testMissingPricingIsUnknown(): void
    {
        $price = ListedModelPrice::fromListingItem(['id' => 'local/model']);

        self::assertFalse($price->known);
        self::assertNull($price->priceInPerMillion);
        self::assertNull($price->priceOutPerMillion);
    }

    public function testNegativeOrNonNumericPriceIsUnknown(): void
    {
        self::assertFalse(ListedModelPrice::fromListingItem([
            'pricing' => ['prompt' => '-1', 'completion' => '0.000001'],
        ])->known);
        self::assertFalse(ListedModelPrice::fromListingItem([
            'pricing' => ['prompt' => 'n/a', 'completion' => '0'],
        ])->known);
    }
}
