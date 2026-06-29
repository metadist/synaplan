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
}
