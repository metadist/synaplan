<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\Message\Routing\NativeToolRoutingConfig;
use Doctrine\DBAL\Connection;

/**
 * Idempotent seeder for the global native-tool-routing flag (BCONFIG, ownerId=0).
 *
 * Insert-if-missing only — operator overrides are never touched. Seeds
 * ENABLED=0 for the same reason as {@see EmbeddingRouterConfigSeeder}, only
 * more so: this flag moves the routing decision into the answering call on the
 * single hottest path in the product, so it stays off until an operator turns
 * it on deliberately.
 *
 * Default OFF also means existing installs need no migration to stay
 * behaviour-identical — unlike {@see StructuredOutputConfigSeeder}'s ON
 * default, which needed {@see \DoctrineMigrations\Version20260901000000} to
 * reach installs a seeder alone cannot touch.
 */
final readonly class NativeToolRoutingConfigSeeder
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function seed(): SeedResult
    {
        $rows = [
            ['ownerId' => 0, 'group' => NativeToolRoutingConfig::CONFIG_GROUP, 'setting' => NativeToolRoutingConfig::KEY_ENABLED, 'value' => '0'],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'native_tool_routing_config', $rows);
    }
}
