<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\Egress\EgressConfig;
use App\Service\Egress\EgressMode;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Seeds `EGRESS.MODE=off` and an empty allowlist. Insert-if-missing: an
 * operator who chose `report` or `enforce` is never overwritten.
 */
#[AsTaggedItem(priority: 64)]
final readonly class EgressConfigSeeder implements SeederInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function label(): string
    {
        return 'egress';
    }

    public function description(): string
    {
        return 'BCONFIG, group=EGRESS — mode off, empty allowlist';
    }

    public function seed(): SeedResult
    {
        return BConfigSeeder::insertIfMissing($this->connection, 'egress_config', [
            [
                'ownerId' => 0,
                'group' => EgressConfig::CONFIG_GROUP,
                'setting' => EgressConfig::KEY_MODE,
                'value' => EgressMode::Off->value,
            ],
            [
                'ownerId' => 0,
                'group' => EgressConfig::CONFIG_GROUP,
                'setting' => EgressConfig::KEY_ALLOW,
                'value' => '[]',
            ],
        ]);
    }
}
