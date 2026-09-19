<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\Compute\ComputeConfig;
use Doctrine\DBAL\Connection;

/**
 * Bootstrap-only COMPUTE.* defaults. Existing rows are never overwritten.
 */
final readonly class ComputeConfigSeeder
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function seed(): SeedResult
    {
        $group = ComputeConfig::CONFIG_GROUP;
        $rows = [
            ['ownerId' => 0, 'group' => $group, 'setting' => ComputeConfig::KEY_ENABLED, 'value' => self::enabledSeedValue()],
            ['ownerId' => 0, 'group' => $group, 'setting' => ComputeConfig::KEY_REQUIRE_TIER, 'value' => ComputeConfig::DEFAULT_REQUIRE_TIER],
            ['ownerId' => 0, 'group' => $group, 'setting' => ComputeConfig::KEY_DEFAULT_TIMEOUT_SEC, 'value' => '60'],
            ['ownerId' => 0, 'group' => $group, 'setting' => ComputeConfig::KEY_DEFAULT_MEMORY_MB, 'value' => '512'],
            ['ownerId' => 0, 'group' => $group, 'setting' => ComputeConfig::KEY_DEFAULT_CPU, 'value' => '1.0'],
            ['ownerId' => 0, 'group' => $group, 'setting' => ComputeConfig::KEY_DEFAULT_PIDS, 'value' => '128'],
            ['ownerId' => 0, 'group' => $group, 'setting' => ComputeConfig::KEY_DEFAULT_OUTPUT_MB, 'value' => '50'],
            ['ownerId' => 0, 'group' => $group, 'setting' => ComputeConfig::KEY_MAX_TIMEOUT_SEC, 'value' => '300'],
            ['ownerId' => 0, 'group' => $group, 'setting' => ComputeConfig::KEY_POLICY_INTERACTIVE, 'value' => ComputeConfig::POLICY_AUTO],
            ['ownerId' => 0, 'group' => $group, 'setting' => ComputeConfig::KEY_POLICY_UNATTENDED, 'value' => ComputeConfig::POLICY_APPROVE],
            ['ownerId' => 0, 'group' => $group, 'setting' => ComputeConfig::KEY_WORKSPACES_ENABLED, 'value' => '0'],
            ['ownerId' => 0, 'group' => $group, 'setting' => ComputeConfig::KEY_EGRESS_ENABLED, 'value' => '0'],
            ['ownerId' => 0, 'group' => $group, 'setting' => ComputeConfig::KEY_EGRESS_REQUIRES_APPROVAL, 'value' => '1'],
            ['ownerId' => 0, 'group' => $group, 'setting' => ComputeConfig::KEY_EGRESS_MAX_HOSTS, 'value' => (string) ComputeConfig::DEFAULT_EGRESS_MAX_HOSTS],
            ['ownerId' => 0, 'group' => $group, 'setting' => ComputeConfig::KEY_WORKSPACE_TTL_DAYS, 'value' => (string) ComputeConfig::DEFAULT_WORKSPACE_TTL_DAYS],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'compute_config', $rows);
    }

    /**
     * New installs start enabled only when a sidecar is wired at seed time.
     * Existing rows are never touched (insertIfMissing) — enabling there is
     * an admin decision in Operate → System config.
     */
    public static function enabledSeedValue(): string
    {
        $url = trim((string) ($_ENV['COMPUTE_URL'] ?? getenv('COMPUTE_URL') ?: ''));
        $token = trim((string) ($_ENV['COMPUTE_TOKEN'] ?? getenv('COMPUTE_TOKEN') ?: ''));

        return ('' !== $url && '' !== $token) ? '1' : '0';
    }
}
