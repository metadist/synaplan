<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\MessagesGateway\MessagesGatewayConfig;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Idempotent seeder for the Anthropic Messages gateway flags (BCONFIG, ownerId=0).
 *
 * All feature flags default OFF except BUDGET_NOTICE_ENABLED and
 * SESSION_SUMMARY_ENABLED (both only take effect once the gateway itself is
 * enabled). Existing operator rows are never overwritten (INSERT IGNORE).
 */
#[AsTaggedItem(priority: 200)]
final readonly class MessagesGatewayConfigSeeder implements SeederInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function label(): string
    {
        return 'messages-gateway';
    }

    public function description(): string
    {
        return 'BCONFIG, group=MESSAGES_GATEWAY, ownerId=0 — default OFF';
    }

    public function seed(): SeedResult
    {
        $rows = [
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_ENABLED, 'value' => '0'],
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_ALLOW_OPERATOR_KEY, 'value' => '0'],
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_MCP_TOOLS_ENABLED, 'value' => '0'],
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_MCP_TOOLS_WITH_CLIENT_TOOLS, 'value' => '0'],
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_MCP_MAX_ITERATIONS, 'value' => '8'],
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_WEB_SEARCH_MODE, 'value' => MessagesGatewayConfig::WEB_SEARCH_AUTO],
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_WEB_SEARCH_MAX_ROUNDS, 'value' => '4'],
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_WEB_FETCH_MODE, 'value' => MessagesGatewayConfig::WEB_FETCH_AUTO],
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_WEB_FETCH_MAX_PER_TURN, 'value' => (string) MessagesGatewayConfig::DEFAULT_WEB_FETCH_MAX_PER_TURN],
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_KNOWLEDGE_TOOLS, 'value' => MessagesGatewayConfig::DEFAULT_KNOWLEDGE_TOOLS],
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_VISION_MODE, 'value' => MessagesGatewayConfig::VISION_AUTO],
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_VISION_IMAGE_DETAIL, 'value' => MessagesGatewayConfig::IMAGE_DETAIL_AUTO],
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_VISION_MAX_IMAGES, 'value' => '0'],
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_CONTEXT_INJECTION_ENABLED, 'value' => '0'],
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_BUDGET_NOTICE_ENABLED, 'value' => '1'],
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_SESSION_SUMMARY_ENABLED, 'value' => '1'],
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_MODEL_ALIASES, 'value' => '{}'],
            ['ownerId' => 0, 'group' => MessagesGatewayConfig::CONFIG_GROUP, 'setting' => MessagesGatewayConfig::KEY_UPSTREAM_URL, 'value' => MessagesGatewayConfig::DEFAULT_UPSTREAM_URL],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'messages_gateway_config', $rows);
    }
}
