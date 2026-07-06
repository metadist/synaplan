<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\Mcp\McpClientConfig;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for the outbound MCP client flags (BCONFIG, ownerId=0).
 *
 * Rollout decision (plan 09 §6 follow-up): the outbound MCP client ships
 * ENABLED on deploy — the global rows are seeded to ON so a platform rollout
 * activates the feature without a manual BCONFIG edit. Operators keep the
 * kill switch: existing rows are never touched (insert-if-missing), so an
 * explicit `MCP.CLIENT_ENABLED = 0` override survives every deploy.
 *
 * Note the remaining per-user gates: connecting a server under
 * Channels → MCP Servers and the per-topic "MCP Data Sources" opt-in
 * (`tool_mcp`) are still required before any call happens.
 */
final readonly class McpConfigSeeder
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function seed(): SeedResult
    {
        $rows = [
            ['ownerId' => 0, 'group' => McpClientConfig::CONFIG_GROUP, 'setting' => McpClientConfig::KEY_CLIENT_ENABLED, 'value' => '1'],
            ['ownerId' => 0, 'group' => McpClientConfig::CONFIG_GROUP, 'setting' => McpClientConfig::KEY_NODE_TIMEOUT, 'value' => '15'],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'mcp_config', $rows);
    }
}
