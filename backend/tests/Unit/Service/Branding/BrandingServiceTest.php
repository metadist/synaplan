<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Branding;

use App\Repository\ConfigRepository;
use App\Service\Branding\BrandingService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class BrandingServiceTest extends TestCase
{
    private ConfigRepository&MockObject $configRepository;
    private BrandingService $service;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->service = new BrandingService($this->configRepository);
    }

    /**
     * An unconfigured deployment must reproduce the historical Synaplan look.
     */
    public function testReturnsDefaultsWhenNothingConfigured(): void
    {
        $this->configRepository->method('getValue')->willReturn(null);

        $branding = $this->service->getBranding();

        $this->assertSame('Synaplan', $branding['name']);
        $this->assertSame('#003fc7', $branding['primaryColor']);
        $this->assertSame('https://www.synaplan.com', $branding['homepageUrl']);
        $this->assertSame('', $branding['logoUrl']);
        $this->assertTrue($branding['showPoweredBy']);
        $this->assertSame('Synaplan', $branding['poweredByLabel']);
    }

    /**
     * Empty-string overrides must fall back to the default, not blank the brand.
     */
    public function testEmptyOverrideFallsBackToDefault(): void
    {
        $this->configRepository->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => BrandingService::KEY_NAME === $setting ? '' : null
        );

        $this->assertSame('Synaplan', $this->service->getBranding()['name']);
    }

    public function testReadsConfiguredOverrides(): void
    {
        $this->configRepository->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => match (true) {
                BrandingService::OWNER_ID !== $owner || BrandingService::GROUP !== $group => null,
                BrandingService::KEY_NAME === $setting => 'Acme',
                BrandingService::KEY_PRIMARY_COLOR === $setting => '#ff0000',
                BrandingService::KEY_LOGO_URL === $setting => 'https://acme.test/logo.svg',
                BrandingService::KEY_SHOW_POWERED_BY === $setting => '0',
                default => null,
            }
        );

        $branding = $this->service->getBranding();

        $this->assertSame('Acme', $branding['name']);
        $this->assertSame('#ff0000', $branding['primaryColor']);
        $this->assertSame('https://acme.test/logo.svg', $branding['logoUrl']);
        $this->assertFalse($branding['showPoweredBy']);
    }

    public function testShowPoweredByAcceptsTrueString(): void
    {
        $this->configRepository->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => BrandingService::KEY_SHOW_POWERED_BY === $setting ? 'true' : null
        );

        $this->assertTrue($this->service->getBranding()['showPoweredBy']);
    }
}
