<?php

declare(strict_types=1);

namespace App\Seed;

use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for rate-limit configuration in BCONFIG (ownerId=0).
 *
 * Seeds:
 * - SYSTEM_FLAGS (smart rate limiting toggles)
 * - RATELIMITS_ANONYMOUS / NEW (lifetime totals — never reset)
 * - RATELIMITS_PRO / TEAM / BUSINESS (hourly + monthly)
 *
 * Operator overrides are preserved (insert-if-missing semantics).
 */
final class RateLimitConfigSeeder
{
    /**
     * @var list<array{ownerId: int, group: string, setting: string, value: string}>
     */
    private const DEFAULTS = [
        // System flags
        ['ownerId' => 0, 'group' => 'SYSTEM_FLAGS', 'setting' => 'SMART_RATE_LIMITING_ENABLED', 'value' => '1'],
        ['ownerId' => 0, 'group' => 'SYSTEM_FLAGS', 'setting' => 'RATE_LIMITING_DEBUG_MODE',    'value' => '0'],

        // ANONYMOUS (no phone verification — lifetime totals, very restricted)
        ['ownerId' => 0, 'group' => 'RATELIMITS_ANONYMOUS', 'setting' => 'MESSAGES_TOTAL',      'value' => '10'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_ANONYMOUS', 'setting' => 'IMAGES_TOTAL',        'value' => '2'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_ANONYMOUS', 'setting' => 'VIDEOS_TOTAL',        'value' => '0'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_ANONYMOUS', 'setting' => 'AUDIOS_TOTAL',        'value' => '0'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_ANONYMOUS', 'setting' => 'FILE_ANALYSIS_TOTAL', 'value' => '3'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_ANONYMOUS', 'setting' => 'FILE_UPLOADS_TOTAL',  'value' => '3'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_ANONYMOUS', 'setting' => 'STORAGE_MB',          'value' => '10'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_ANONYMOUS', 'setting' => 'MAX_OUTPUT_TOKENS',   'value' => '2048'],

        // NEW (phone-verified — lifetime totals)
        ['ownerId' => 0, 'group' => 'RATELIMITS_NEW', 'setting' => 'MESSAGES_TOTAL',      'value' => '50'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_NEW', 'setting' => 'IMAGES_TOTAL',        'value' => '5'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_NEW', 'setting' => 'VIDEOS_TOTAL',        'value' => '2'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_NEW', 'setting' => 'AUDIOS_TOTAL',        'value' => '3'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_NEW', 'setting' => 'FILE_ANALYSIS_TOTAL', 'value' => '10'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_NEW', 'setting' => 'FILE_UPLOADS_TOTAL',  'value' => '10'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_NEW', 'setting' => 'STORAGE_MB',          'value' => '100'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_NEW', 'setting' => 'MAX_OUTPUT_TOKENS',   'value' => '4096'],

        // PRO (hourly + monthly)
        ['ownerId' => 0, 'group' => 'RATELIMITS_PRO', 'setting' => 'MESSAGES_HOURLY',        'value' => '100'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_PRO', 'setting' => 'MESSAGES_MONTHLY',       'value' => '5000'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_PRO', 'setting' => 'IMAGES_MONTHLY',         'value' => '50'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_PRO', 'setting' => 'VIDEOS_MONTHLY',         'value' => '10'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_PRO', 'setting' => 'AUDIOS_MONTHLY',         'value' => '20'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_PRO', 'setting' => 'FILE_ANALYSIS_MONTHLY',  'value' => '200'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_PRO', 'setting' => 'FILE_UPLOADS_MONTHLY',   'value' => '200'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_PRO', 'setting' => 'STORAGE_GB',             'value' => '5'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_PRO', 'setting' => 'MAX_OUTPUT_TOKENS',      'value' => '16384'],

        // TEAM
        ['ownerId' => 0, 'group' => 'RATELIMITS_TEAM', 'setting' => 'MESSAGES_HOURLY',       'value' => '300'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_TEAM', 'setting' => 'MESSAGES_MONTHLY',      'value' => '15000'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_TEAM', 'setting' => 'IMAGES_MONTHLY',        'value' => '200'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_TEAM', 'setting' => 'VIDEOS_MONTHLY',        'value' => '50'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_TEAM', 'setting' => 'AUDIOS_MONTHLY',        'value' => '100'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_TEAM', 'setting' => 'FILE_ANALYSIS_MONTHLY', 'value' => '1000'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_TEAM', 'setting' => 'FILE_UPLOADS_MONTHLY',  'value' => '1000'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_TEAM', 'setting' => 'STORAGE_GB',            'value' => '20'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_TEAM', 'setting' => 'MAX_OUTPUT_TOKENS',     'value' => '32768'],

        // BUSINESS
        ['ownerId' => 0, 'group' => 'RATELIMITS_BUSINESS', 'setting' => 'MESSAGES_HOURLY',       'value' => '1000'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_BUSINESS', 'setting' => 'MESSAGES_MONTHLY',      'value' => '50000'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_BUSINESS', 'setting' => 'IMAGES_MONTHLY',        'value' => '1000'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_BUSINESS', 'setting' => 'VIDEOS_MONTHLY',        'value' => '200'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_BUSINESS', 'setting' => 'AUDIOS_MONTHLY',        'value' => '500'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_BUSINESS', 'setting' => 'FILE_ANALYSIS_MONTHLY', 'value' => '5000'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_BUSINESS', 'setting' => 'FILE_UPLOADS_MONTHLY',  'value' => '5000'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_BUSINESS', 'setting' => 'STORAGE_GB',            'value' => '100'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_BUSINESS', 'setting' => 'MAX_OUTPUT_TOKENS',     'value' => '65536'],
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function seed(): SeedResult
    {
        return BConfigSeeder::insertIfMissing($this->connection, 'rate_limits', self::DEFAULTS);
    }
}
