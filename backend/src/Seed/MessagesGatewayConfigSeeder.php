<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\MessagesGateway\MessagesGatewayConfig;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for the Anthropic Messages gateway flags (BCONFIG, ownerId=0).
 *
 * All feature flags default OFF except BUDGET_NOTICE_ENABLED and
 * SESSION_SUMMARY_ENABLED (both only take effect once the gateway itself is
 * enabled). Existing operator rows are never overwritten (INSERT IGNORE).
 */
final readonly class MessagesGatewayConfigSeeder
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function seed(): SeedResult
    {
        $rows = [
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_ENABLED, 'value' => '0'],
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_ALLOW_OPERATOR_KEY, 'value' => '0'],
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_MCP_TOOLS_ENABLED, 'value' => '0'],
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_MCP_TOOLS_WITH_CLIENT_TOOLS, 'value' => '0'],
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_MCP_MAX_ITERATIONS, 'value' => '8'],
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_WEB_SEARCH_ENABLED, 'value' => '0'],
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_CONTEXT_INJECTION_ENABLED, 'value' => '0'],
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_BUDGET_NOTICE_ENABLED, 'value' => '1'],
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_SESSION_SUMMARY_ENABLED, 'value' => '1'],
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_MODEL_ALIASES, 'value' => '{}'],
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_UPSTREAM_URL, 'value' => MessagesGatewayConfig::DEFAULT_UPSTREAM_URL],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'messages_gateway_config', $rows);
    }
}
