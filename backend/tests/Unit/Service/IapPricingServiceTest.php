<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\IapPricingService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the IAP product ↔ tier mapping (Epic 5.5).
 */
final class IapPricingServiceTest extends TestCase
{
    private function configured(): IapPricingService
    {
        return new IapPricingService('app.pro', 'app.team', 'app.business');
    }

    public function testMapsConfiguredProductIdsToTiers(): void
    {
        $svc = $this->configured();

        $this->assertSame('PRO', $svc->mapProductIdToLevel('app.pro'));
        $this->assertSame('TEAM', $svc->mapProductIdToLevel('app.team'));
        $this->assertSame('BUSINESS', $svc->mapProductIdToLevel('app.business'));
    }

    public function testUnknownOrEmptyProductGrantsNoTier(): void
    {
        $svc = $this->configured();

        $this->assertSame('NEW', $svc->mapProductIdToLevel('app.unknown'));
        $this->assertSame('NEW', $svc->mapProductIdToLevel(''));
        $this->assertSame('NEW', $svc->mapProductIdToLevel(null));
    }

    public function testProductIdForTier(): void
    {
        $svc = $this->configured();

        $this->assertSame('app.pro', $svc->productIdForTier('PRO'));
        $this->assertSame('app.business', $svc->productIdForTier('BUSINESS'));
        $this->assertNull($svc->productIdForTier('NEW'));
        $this->assertNull($svc->productIdForTier('ADMIN'));
    }

    public function testProductCatalogueIsTierOrdered(): void
    {
        $svc = $this->configured();

        $this->assertSame(['PRO', 'TEAM', 'BUSINESS'], array_keys($svc->productCatalogue()));
    }

    public function testIsConfiguredFalseForPlaceholderDefaults(): void
    {
        // Default constructor = documented placeholders → IAP effectively off.
        $svc = new IapPricingService();

        $this->assertFalse($svc->isConfigured());
    }

    public function testIsConfiguredTrueWhenAnyRealProductSet(): void
    {
        $svc = new IapPricingService('com.real.pro', 'com.synaplan.app.team.monthly', 'com.synaplan.app.business.monthly');

        $this->assertTrue($svc->isConfigured());
    }

    public function testAppPriceAddsDefaultStoreMarkupAndSnapsToPricePoint(): void
    {
        // Default markup is 30 % — the Apple/Google commission passed on to app
        // buyers — snapped to the nearest x.99 store price point.
        $svc = new IapPricingService();

        $this->assertSame(25.99, $svc->appPrice(19.95)); // 25.935 → 25.99
        $this->assertSame(64.99, $svc->appPrice(49.95)); // 64.935 → 64.99
        $this->assertSame(129.99, $svc->appPrice(99.95)); // 129.935 → 129.99
        $this->assertSame(30.0, $svc->storeMarkupPercent());
    }

    public function testAppPriceHonoursConfiguredMarkup(): void
    {
        $svc = new IapPricingService(storeMarkupPercent: 15.0);

        $this->assertSame(22.99, $svc->appPrice(19.95)); // 22.9425 → 22.99
        $this->assertSame(15.0, $svc->storeMarkupPercent());
    }

    public function testAppPriceSnapsDownToTheNearestPricePoint(): void
    {
        // "Nearest" can also round DOWN — as long as it stays >= the web price.
        $svc = new IapPricingService(storeMarkupPercent: 32.0);

        // 20.00 * 1.32 = 26.40 → nearest price point is 25.99 (not 26.99).
        $this->assertSame(25.99, $svc->appPrice(20.00));
    }

    public function testAppPriceNeverDiscountsOnNegativeMarkup(): void
    {
        // A misconfigured negative markup must never UNDERCUT the web price.
        $svc = new IapPricingService(storeMarkupPercent: -10.0);

        $this->assertSame(19.99, $svc->appPrice(19.95));
        $this->assertSame(0.0, $svc->storeMarkupPercent());
    }
}
