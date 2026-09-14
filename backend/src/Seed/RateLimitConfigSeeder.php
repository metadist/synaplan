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
 *
 * MAX_OUTPUT_TOKENS is intentionally seeded ONLY for ANONYMOUS: authenticated
 * tiers get the model's full max_tokens. {@see \App\Service\Message\Handler\ChatHandler}
 * applies this plan limit as an additional output clamp only when present — i.e.
 * only for ANONYMOUS; for all other tiers the limit is null, so the model's own
 * max_tokens is used unchanged. Existing installs are migrated by the dedicated
 * migration that deletes the legacy NEW/PRO/TEAM/BUSINESS rows (BCONFIG defaults
 * are bootstrap-only).
 */
final readonly class RateLimitConfigSeeder
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
        // ANONYMOUS is the ONLY tier with a hard output cap. Authenticated tiers
        // (NEW/PRO/TEAM/BUSINESS) intentionally have no MAX_OUTPUT_TOKENS so they
        // receive the selected model's full max_tokens; their spend is bounded by
        // the cost-budget gate (registered) and message-count limits instead.
        ['ownerId' => 0, 'group' => 'RATELIMITS_ANONYMOUS', 'setting' => 'MAX_OUTPUT_TOKENS',   'value' => '2048'],
        // COMPUTE_RUNS_* is enforced by RateLimitService::checkLimit.
        // COMPUTE_CONCURRENT and COMPUTE_CPU_SECONDS_DAILY are enforced by CodeRunRunner.
        // COMPUTE_WORKSPACE_MB is reserved for B3 user workspaces; unused until then.
        ['ownerId' => 0, 'group' => 'RATELIMITS_ANONYMOUS', 'setting' => 'COMPUTE_RUNS_TOTAL',  'value' => '0'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_ANONYMOUS', 'setting' => 'COMPUTE_CONCURRENT',  'value' => '0'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_ANONYMOUS', 'setting' => 'COMPUTE_CPU_SECONDS_DAILY', 'value' => '0'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_ANONYMOUS', 'setting' => 'COMPUTE_WORKSPACE_MB', 'value' => '0'],

        // NEW (phone-verified — lifetime totals)
        ['ownerId' => 0, 'group' => 'RATELIMITS_NEW', 'setting' => 'MESSAGES_TOTAL',      'value' => '50'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_NEW', 'setting' => 'IMAGES_TOTAL',        'value' => '5'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_NEW', 'setting' => 'VIDEOS_TOTAL',        'value' => '2'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_NEW', 'setting' => 'AUDIOS_TOTAL',        'value' => '3'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_NEW', 'setting' => 'FILE_ANALYSIS_TOTAL', 'value' => '10'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_NEW', 'setting' => 'FILE_UPLOADS_TOTAL',  'value' => '10'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_NEW', 'setting' => 'STORAGE_MB',          'value' => '100'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_NEW', 'setting' => 'COMPUTE_RUNS_TOTAL',  'value' => '5'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_NEW', 'setting' => 'COMPUTE_CONCURRENT',  'value' => '1'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_NEW', 'setting' => 'COMPUTE_CPU_SECONDS_DAILY', 'value' => '60'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_NEW', 'setting' => 'COMPUTE_WORKSPACE_MB', 'value' => '256'],

        // PRO (hourly + monthly)
        ['ownerId' => 0, 'group' => 'RATELIMITS_PRO', 'setting' => 'MESSAGES_HOURLY',        'value' => '100'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_PRO', 'setting' => 'MESSAGES_MONTHLY',       'value' => '5000'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_PRO', 'setting' => 'IMAGES_MONTHLY',         'value' => '50'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_PRO', 'setting' => 'VIDEOS_MONTHLY',         'value' => '10'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_PRO', 'setting' => 'AUDIOS_MONTHLY',         'value' => '20'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_PRO', 'setting' => 'FILE_ANALYSIS_MONTHLY',  'value' => '200'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_PRO', 'setting' => 'FILE_UPLOADS_MONTHLY',   'value' => '200'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_PRO', 'setting' => 'STORAGE_GB',             'value' => '5'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_PRO', 'setting' => 'COMPUTE_RUNS_HOURLY',    'value' => '10'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_PRO', 'setting' => 'COMPUTE_CONCURRENT',     'value' => '2'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_PRO', 'setting' => 'COMPUTE_CPU_SECONDS_DAILY', 'value' => '300'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_PRO', 'setting' => 'COMPUTE_WORKSPACE_MB',   'value' => '512'],

        // TEAM
        ['ownerId' => 0, 'group' => 'RATELIMITS_TEAM', 'setting' => 'MESSAGES_HOURLY',       'value' => '300'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_TEAM', 'setting' => 'MESSAGES_MONTHLY',      'value' => '15000'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_TEAM', 'setting' => 'IMAGES_MONTHLY',        'value' => '200'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_TEAM', 'setting' => 'VIDEOS_MONTHLY',        'value' => '50'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_TEAM', 'setting' => 'AUDIOS_MONTHLY',        'value' => '100'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_TEAM', 'setting' => 'FILE_ANALYSIS_MONTHLY', 'value' => '1000'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_TEAM', 'setting' => 'FILE_UPLOADS_MONTHLY',  'value' => '1000'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_TEAM', 'setting' => 'STORAGE_GB',            'value' => '20'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_TEAM', 'setting' => 'COMPUTE_RUNS_HOURLY',   'value' => '30'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_TEAM', 'setting' => 'COMPUTE_CONCURRENT',    'value' => '4'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_TEAM', 'setting' => 'COMPUTE_CPU_SECONDS_DAILY', 'value' => '900'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_TEAM', 'setting' => 'COMPUTE_WORKSPACE_MB',  'value' => '1024'],

        // BUSINESS
        ['ownerId' => 0, 'group' => 'RATELIMITS_BUSINESS', 'setting' => 'MESSAGES_HOURLY',       'value' => '1000'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_BUSINESS', 'setting' => 'MESSAGES_MONTHLY',      'value' => '50000'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_BUSINESS', 'setting' => 'IMAGES_MONTHLY',        'value' => '1000'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_BUSINESS', 'setting' => 'VIDEOS_MONTHLY',        'value' => '200'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_BUSINESS', 'setting' => 'AUDIOS_MONTHLY',        'value' => '500'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_BUSINESS', 'setting' => 'FILE_ANALYSIS_MONTHLY', 'value' => '5000'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_BUSINESS', 'setting' => 'FILE_UPLOADS_MONTHLY',  'value' => '5000'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_BUSINESS', 'setting' => 'STORAGE_GB',            'value' => '100'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_BUSINESS', 'setting' => 'COMPUTE_RUNS_HOURLY',   'value' => '100'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_BUSINESS', 'setting' => 'COMPUTE_CONCURRENT',    'value' => '4'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_BUSINESS', 'setting' => 'COMPUTE_CPU_SECONDS_DAILY', 'value' => '3600'],
        ['ownerId' => 0, 'group' => 'RATELIMITS_BUSINESS', 'setting' => 'COMPUTE_WORKSPACE_MB',  'value' => '2048'],
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function seed(): SeedResult
    {
        return BConfigSeeder::insertIfMissing($this->connection, 'rate_limits', self::DEFAULTS);
    }
}
