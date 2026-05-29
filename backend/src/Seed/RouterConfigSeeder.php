<?php

declare(strict_types=1);

namespace App\Seed;

use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for the external synaplan-router configuration.
 *
 * Seeds default BCONFIG rows for the ROUTER group. These control whether
 * the external SetFit/ONNX router is used as Tier 1 in SynapseRouter,
 * along with connection parameters and circuit breaker settings.
 *
 * Operator overrides are NEVER touched (INSERT IGNORE semantics).
 */
final readonly class RouterConfigSeeder
{
    /**
     * @var list<array{ownerId: int, group: string, setting: string, value: string}>
     */
    private const DEFAULTS = [
        ['ownerId' => 0, 'group' => 'ROUTER', 'setting' => 'ENABLED',                         'value' => 'false'],
        ['ownerId' => 0, 'group' => 'ROUTER', 'setting' => 'SERVICE_URL',                     'value' => 'http://router:8000'],
        ['ownerId' => 0, 'group' => 'ROUTER', 'setting' => 'CONFIDENCE_THRESHOLD',            'value' => '0.70'],
        ['ownerId' => 0, 'group' => 'ROUTER', 'setting' => 'TIMEOUT_MS',                      'value' => '100'],
        ['ownerId' => 0, 'group' => 'ROUTER', 'setting' => 'CIRCUIT_BREAKER_THRESHOLD',       'value' => '3'],
        ['ownerId' => 0, 'group' => 'ROUTER', 'setting' => 'CIRCUIT_BREAKER_RESET_S',         'value' => '60'],
        ['ownerId' => 0, 'group' => 'ROUTER', 'setting' => 'FEEDBACK_VERIFICATION_ENABLED',   'value' => 'true'],
        ['ownerId' => 0, 'group' => 'ROUTER', 'setting' => 'FEEDBACK_VERIFICATION_MODEL',     'value' => ''],
        ['ownerId' => 0, 'group' => 'ROUTER', 'setting' => 'FEEDBACK_RATE_LIMIT_PER_MINUTE',  'value' => '5'],
    ];

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function seed(): SeedResult
    {
        return BConfigSeeder::insertIfMissing($this->connection, 'router_config', self::DEFAULTS);
    }
}
