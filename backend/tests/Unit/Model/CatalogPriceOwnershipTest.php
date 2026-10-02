<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\CatalogPriceOwnership;
use App\Model\ModelCatalog;
use PHPUnit\Framework\TestCase;

final class CatalogPriceOwnershipTest extends TestCase
{
    public function testPinnedCatalogDoesNotKeepTheLiteLlmPrice(): void
    {
        $catalog = $this->pricedRow();
        $synced = CatalogPriceOwnership::stamp([
            ...$catalog,
            'priceIn' => 8.5,
            'priceOut' => 9.5,
        ], $catalog);

        self::assertTrue(CatalogPriceOwnership::shouldKeepLiteLlmPrice($synced, $catalog));

        $catalog['json'][CatalogPriceOwnership::PRICE_PINNED_KEY] = true;
        self::assertTrue(CatalogPriceOwnership::isPricePinned($catalog));
        self::assertFalse(CatalogPriceOwnership::shouldKeepLiteLlmPrice($synced, $catalog));
        self::assertFalse(CatalogPriceOwnership::needsOwnershipStamp($synced, $catalog));
    }

    public function testCatalogPriceMoveDropsLiteLlmOwnership(): void
    {
        $catalog = $this->pricedRow();
        $synced = CatalogPriceOwnership::stamp([
            ...$catalog,
            'priceIn' => 8.5,
        ], $catalog);

        self::assertTrue(CatalogPriceOwnership::shouldKeepLiteLlmPrice($synced, $catalog));

        $moved = $catalog;
        $moved['priceIn'] = (float) $catalog['priceIn'] + 1;
        self::assertFalse(CatalogPriceOwnership::shouldKeepLiteLlmPrice($synced, $moved));
        self::assertFalse(CatalogPriceOwnership::needsOwnershipStamp($synced, $moved));
    }

    public function testMergeKeepsTheLivePriceAndTheSyncStamp(): void
    {
        $catalog = $this->pricedRow();
        $synced = CatalogPriceOwnership::stamp([
            ...$catalog,
            'priceIn' => 8.5,
            'priceOut' => 9.5,
        ], $catalog);
        $catalog['name'] = 'Renamed in the catalog';

        $merged = CatalogPriceOwnership::mergeKeepingLiteLlmPrice($synced, $catalog);

        self::assertSame('Renamed in the catalog', $merged['name']);
        self::assertEqualsWithDelta(8.5, (float) $merged['priceIn'], 0.000001);
        self::assertEqualsWithDelta(9.5, (float) $merged['priceOut'], 0.000001);
        self::assertSame(
            CatalogPriceOwnership::PRICE_OWNER_LITELLM,
            $merged['json'][CatalogPriceOwnership::PRICE_OWNER_KEY],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function pricedRow(): array
    {
        foreach (ModelCatalog::all() as $row) {
            if ((float) ($row['priceIn'] ?? 0) > 0) {
                return $row;
            }
        }

        self::fail('Catalog has no positively priced row');
    }
}
