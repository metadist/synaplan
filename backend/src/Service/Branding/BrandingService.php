<?php

declare(strict_types=1);

namespace App\Service\Branding;

use App\Repository\ConfigRepository;

/**
 * Single source of truth for white-label branding (Epic 4).
 *
 * Reads the BRANDING config group (BCONFIG, ownerId=0) and exposes it as a
 * typed array. Defaults reproduce the historical hardcoded "Synaplan" look, so
 * an unconfigured deployment is byte-identical to before this epic.
 *
 * Consumed by the public runtime-config endpoint (frontend) and the SSR
 * shared-chat page (og:site_name) so every surface agrees on one brand.
 */
final readonly class BrandingService
{
    public const GROUP = 'BRANDING';
    public const OWNER_ID = 0;

    public const KEY_NAME = 'BRAND_NAME';
    public const KEY_TAGLINE = 'BRAND_TAGLINE';
    public const KEY_PRIMARY_COLOR = 'BRAND_PRIMARY_COLOR';
    public const KEY_SECONDARY_COLOR = 'BRAND_SECONDARY_COLOR';
    public const KEY_ACCENT_COLOR = 'BRAND_ACCENT_COLOR';
    public const KEY_FONT_FAMILY = 'BRAND_FONT_FAMILY';
    public const KEY_HEADING_FONT_FAMILY = 'BRAND_HEADING_FONT_FAMILY';
    public const KEY_FONT_URL = 'BRAND_FONT_URL';
    public const KEY_LOGO_URL = 'BRAND_LOGO_URL';
    public const KEY_LOGO_DARK_URL = 'BRAND_LOGO_DARK_URL';
    public const KEY_ICON_URL = 'BRAND_ICON_URL';
    public const KEY_HOMEPAGE_URL = 'BRAND_HOMEPAGE_URL';
    // MOBILE-APP SEAM (Epic 9.3): privacy-policy + terms-of-use links. Store
    // policy (Apple/Google) requires both reachable in-app and in store metadata;
    // making them configurable lets white-label brands point at their own legal
    // pages instead of Synaplan's.
    public const KEY_PRIVACY_URL = 'BRAND_PRIVACY_URL';
    public const KEY_TERMS_URL = 'BRAND_TERMS_URL';
    public const KEY_LANDING_PAGE = 'BRAND_LANDING_PAGE';
    public const KEY_DEFAULT_ROUTE = 'BRAND_DEFAULT_ROUTE';
    public const KEY_SHOW_POWERED_BY = 'BRAND_SHOW_POWERED_BY';
    public const KEY_POWERED_BY_LABEL = 'BRAND_POWERED_BY_LABEL';
    public const KEY_POWERED_BY_URL = 'BRAND_POWERED_BY_URL';

    public const DEFAULT_NAME = 'Synaplan';
    public const DEFAULT_TAGLINE = '';
    public const DEFAULT_PRIMARY_COLOR = '#003fc7';
    public const DEFAULT_SECONDARY_COLOR = '';
    public const DEFAULT_ACCENT_COLOR = '';
    public const DEFAULT_FONT_FAMILY = '';
    public const DEFAULT_HEADING_FONT_FAMILY = '';
    public const DEFAULT_FONT_URL = '';
    public const DEFAULT_HOMEPAGE_URL = 'https://www.synaplan.com';
    public const DEFAULT_PRIVACY_URL = 'https://www.synaplan.com/privacy-policy';
    public const DEFAULT_TERMS_URL = 'https://www.synaplan.com/terms';
    public const DEFAULT_LANDING_PAGE = '';
    public const DEFAULT_DEFAULT_ROUTE = '';
    public const DEFAULT_SHOW_POWERED_BY = '1';
    public const DEFAULT_POWERED_BY_LABEL = 'Synaplan';
    public const DEFAULT_POWERED_BY_URL = 'https://www.synaplan.com';

    public function __construct(
        private ConfigRepository $configRepository,
    ) {
    }

    /**
     * @return array{
     *     name: string,
     *     tagline: string,
     *     primaryColor: string,
     *     secondaryColor: string,
     *     accentColor: string,
     *     fontFamily: string,
     *     headingFontFamily: string,
     *     fontUrl: string,
     *     logoUrl: string,
     *     logoDarkUrl: string,
     *     iconUrl: string,
     *     homepageUrl: string,
     *     privacyUrl: string,
     *     termsUrl: string,
     *     landingPage: string,
     *     defaultRoute: string,
     *     showPoweredBy: bool,
     *     poweredByLabel: string,
     *     poweredByUrl: string
     * }
     */
    public function getBranding(): array
    {
        return [
            'name' => $this->value(self::KEY_NAME, self::DEFAULT_NAME),
            'tagline' => $this->value(self::KEY_TAGLINE, self::DEFAULT_TAGLINE),
            'primaryColor' => $this->value(self::KEY_PRIMARY_COLOR, self::DEFAULT_PRIMARY_COLOR),
            'secondaryColor' => $this->value(self::KEY_SECONDARY_COLOR, self::DEFAULT_SECONDARY_COLOR),
            'accentColor' => $this->value(self::KEY_ACCENT_COLOR, self::DEFAULT_ACCENT_COLOR),
            'fontFamily' => $this->value(self::KEY_FONT_FAMILY, self::DEFAULT_FONT_FAMILY),
            'headingFontFamily' => $this->value(self::KEY_HEADING_FONT_FAMILY, self::DEFAULT_HEADING_FONT_FAMILY),
            'fontUrl' => $this->value(self::KEY_FONT_URL, self::DEFAULT_FONT_URL),
            'logoUrl' => $this->value(self::KEY_LOGO_URL, ''),
            'logoDarkUrl' => $this->value(self::KEY_LOGO_DARK_URL, ''),
            'iconUrl' => $this->value(self::KEY_ICON_URL, ''),
            'homepageUrl' => $this->value(self::KEY_HOMEPAGE_URL, self::DEFAULT_HOMEPAGE_URL),
            'privacyUrl' => $this->value(self::KEY_PRIVACY_URL, self::DEFAULT_PRIVACY_URL),
            'termsUrl' => $this->value(self::KEY_TERMS_URL, self::DEFAULT_TERMS_URL),
            'landingPage' => $this->value(self::KEY_LANDING_PAGE, self::DEFAULT_LANDING_PAGE),
            'defaultRoute' => $this->value(self::KEY_DEFAULT_ROUTE, self::DEFAULT_DEFAULT_ROUTE),
            'showPoweredBy' => $this->boolValue(self::KEY_SHOW_POWERED_BY, self::DEFAULT_SHOW_POWERED_BY),
            'poweredByLabel' => $this->value(self::KEY_POWERED_BY_LABEL, self::DEFAULT_POWERED_BY_LABEL),
            'poweredByUrl' => $this->value(self::KEY_POWERED_BY_URL, self::DEFAULT_POWERED_BY_URL),
        ];
    }

    private function value(string $setting, string $default): string
    {
        $raw = $this->configRepository->getValue(self::OWNER_ID, self::GROUP, $setting);

        return (null === $raw || '' === $raw) ? $default : $raw;
    }

    private function boolValue(string $setting, string $default): bool
    {
        $raw = $this->configRepository->getValue(self::OWNER_ID, self::GROUP, $setting) ?? $default;

        return '1' === $raw || 'true' === strtolower(trim($raw));
    }
}
