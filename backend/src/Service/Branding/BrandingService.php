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
    public const KEY_LOGO_URL = 'BRAND_LOGO_URL';
    public const KEY_LOGO_DARK_URL = 'BRAND_LOGO_DARK_URL';
    public const KEY_ICON_URL = 'BRAND_ICON_URL';
    public const KEY_HOMEPAGE_URL = 'BRAND_HOMEPAGE_URL';
    public const KEY_SHOW_POWERED_BY = 'BRAND_SHOW_POWERED_BY';
    public const KEY_POWERED_BY_LABEL = 'BRAND_POWERED_BY_LABEL';
    public const KEY_POWERED_BY_URL = 'BRAND_POWERED_BY_URL';

    public const DEFAULT_NAME = 'Synaplan';
    public const DEFAULT_TAGLINE = '';
    public const DEFAULT_PRIMARY_COLOR = '#003fc7';
    public const DEFAULT_HOMEPAGE_URL = 'https://www.synaplan.com';
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
     *     logoUrl: string,
     *     logoDarkUrl: string,
     *     iconUrl: string,
     *     homepageUrl: string,
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
            'logoUrl' => $this->value(self::KEY_LOGO_URL, ''),
            'logoDarkUrl' => $this->value(self::KEY_LOGO_DARK_URL, ''),
            'iconUrl' => $this->value(self::KEY_ICON_URL, ''),
            'homepageUrl' => $this->value(self::KEY_HOMEPAGE_URL, self::DEFAULT_HOMEPAGE_URL),
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
