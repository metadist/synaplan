<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\Branding\BrandingService;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for the white-label branding defaults (BCONFIG, ownerId=0).
 *
 * Seeds the explicit global rows so the brand is visible/editable in the admin
 * System Config UI rather than living only as a code default. Insert-if-missing
 * only — operator overrides are never touched.
 *
 * Defaults reproduce the historical "Synaplan" look, so seeding a fresh install
 * leaves the product visually unchanged.
 */
final readonly class BrandingConfigSeeder
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function seed(): SeedResult
    {
        $g = BrandingService::GROUP;
        $rows = [
            ['ownerId' => 0, 'group' => $g, 'setting' => BrandingService::KEY_NAME,                'value' => BrandingService::DEFAULT_NAME],
            ['ownerId' => 0, 'group' => $g, 'setting' => BrandingService::KEY_TAGLINE,             'value' => BrandingService::DEFAULT_TAGLINE],
            ['ownerId' => 0, 'group' => $g, 'setting' => BrandingService::KEY_PRIMARY_COLOR,       'value' => BrandingService::DEFAULT_PRIMARY_COLOR],
            ['ownerId' => 0, 'group' => $g, 'setting' => BrandingService::KEY_SECONDARY_COLOR,     'value' => BrandingService::DEFAULT_SECONDARY_COLOR],
            ['ownerId' => 0, 'group' => $g, 'setting' => BrandingService::KEY_ACCENT_COLOR,        'value' => BrandingService::DEFAULT_ACCENT_COLOR],
            ['ownerId' => 0, 'group' => $g, 'setting' => BrandingService::KEY_FONT_FAMILY,         'value' => BrandingService::DEFAULT_FONT_FAMILY],
            ['ownerId' => 0, 'group' => $g, 'setting' => BrandingService::KEY_HEADING_FONT_FAMILY, 'value' => BrandingService::DEFAULT_HEADING_FONT_FAMILY],
            ['ownerId' => 0, 'group' => $g, 'setting' => BrandingService::KEY_FONT_URL,            'value' => BrandingService::DEFAULT_FONT_URL],
            ['ownerId' => 0, 'group' => $g, 'setting' => BrandingService::KEY_LOGO_URL,            'value' => ''],
            ['ownerId' => 0, 'group' => $g, 'setting' => BrandingService::KEY_LOGO_DARK_URL,       'value' => ''],
            ['ownerId' => 0, 'group' => $g, 'setting' => BrandingService::KEY_ICON_URL,            'value' => ''],
            ['ownerId' => 0, 'group' => $g, 'setting' => BrandingService::KEY_HOMEPAGE_URL,        'value' => BrandingService::DEFAULT_HOMEPAGE_URL],
            ['ownerId' => 0, 'group' => $g, 'setting' => BrandingService::KEY_LANDING_PAGE,        'value' => BrandingService::DEFAULT_LANDING_PAGE],
            ['ownerId' => 0, 'group' => $g, 'setting' => BrandingService::KEY_DEFAULT_ROUTE,       'value' => BrandingService::DEFAULT_DEFAULT_ROUTE],
            ['ownerId' => 0, 'group' => $g, 'setting' => BrandingService::KEY_SHOW_POWERED_BY,     'value' => BrandingService::DEFAULT_SHOW_POWERED_BY],
            ['ownerId' => 0, 'group' => $g, 'setting' => BrandingService::KEY_POWERED_BY_LABEL,    'value' => BrandingService::DEFAULT_POWERED_BY_LABEL],
            ['ownerId' => 0, 'group' => $g, 'setting' => BrandingService::KEY_POWERED_BY_URL,      'value' => BrandingService::DEFAULT_POWERED_BY_URL],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'branding_config', $rows);
    }
}
