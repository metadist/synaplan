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
        $this->assertSame('https://www.synaplan.com/privacy-policy', $branding['privacyUrl']);
        $this->assertSame('https://www.synaplan.com/terms', $branding['termsUrl']);
        $this->assertSame('', $branding['logoUrl']);
        $this->assertTrue($branding['showPoweredBy']);
        $this->assertSame('Synaplan', $branding['poweredByLabel']);

        // New revision fields default to empty (= keep current look / no gate).
        $this->assertSame('', $branding['secondaryColor']);
        $this->assertSame('', $branding['accentColor']);
        $this->assertSame('', $branding['primaryColorDark']);
        $this->assertSame('', $branding['secondaryColorDark']);
        $this->assertSame('', $branding['accentColorDark']);
        $this->assertSame('', $branding['fontFamily']);
        $this->assertSame('', $branding['headingFontFamily']);
        $this->assertSame('', $branding['fontUrl']);
        $this->assertSame('', $branding['landingPage']);
        $this->assertSame('', $branding['defaultRoute']);
    }

    public function testReadsConfiguredFontsAndRoutes(): void
    {
        $this->configRepository->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => match ($setting) {
                BrandingService::KEY_FONT_FAMILY => 'Inter, sans-serif',
                BrandingService::KEY_HEADING_FONT_FAMILY => 'Lora, serif',
                BrandingService::KEY_FONT_URL => 'https://fonts.example/inter.css',
                BrandingService::KEY_SECONDARY_COLOR => '#112233',
                BrandingService::KEY_ACCENT_COLOR => '#445566',
                BrandingService::KEY_PRIMARY_COLOR_DARK => '#6d9ae0',
                BrandingService::KEY_SECONDARY_COLOR_DARK => '#778899',
                BrandingService::KEY_ACCENT_COLOR_DARK => '#aabbcc',
                BrandingService::KEY_LANDING_PAGE => 'login',
                BrandingService::KEY_DEFAULT_ROUTE => 'chat',
                BrandingService::KEY_PRIVACY_URL => 'https://brand.example/privacy',
                BrandingService::KEY_TERMS_URL => 'https://brand.example/terms',
                default => null,
            }
        );

        $branding = $this->service->getBranding();

        $this->assertSame('https://brand.example/privacy', $branding['privacyUrl']);
        $this->assertSame('https://brand.example/terms', $branding['termsUrl']);
        $this->assertSame('Inter, sans-serif', $branding['fontFamily']);
        $this->assertSame('Lora, serif', $branding['headingFontFamily']);
        $this->assertSame('https://fonts.example/inter.css', $branding['fontUrl']);
        $this->assertSame('#112233', $branding['secondaryColor']);
        $this->assertSame('#445566', $branding['accentColor']);
        $this->assertSame('#6d9ae0', $branding['primaryColorDark']);
        $this->assertSame('#778899', $branding['secondaryColorDark']);
        $this->assertSame('#aabbcc', $branding['accentColorDark']);
        $this->assertSame('login', $branding['landingPage']);
        $this->assertSame('chat', $branding['defaultRoute']);
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
