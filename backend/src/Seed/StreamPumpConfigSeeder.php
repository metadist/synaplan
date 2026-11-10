<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\Chat\Run\StreamPumpConfig;
use App\Service\Chat\Run\StreamPumpMode;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Seeds the pump off. Insert-if-missing: an operator who turned it on is
 * never overwritten.
 */
#[AsTaggedItem(priority: 63)]
final readonly class StreamPumpConfigSeeder implements SeederInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function label(): string
    {
        return 'stream_pump_config';
    }

    public function description(): string
    {
        return 'BCONFIG, group=RUNTIME — stream pump off';
    }

    public function seed(): SeedResult
    {
        return BConfigSeeder::insertIfMissing($this->connection, 'stream_pump_config', [
            [
                'ownerId' => 0,
                'group' => StreamPumpConfig::CONFIG_GROUP,
                'setting' => StreamPumpConfig::KEY_MODE,
                'value' => StreamPumpMode::Off->value,
            ],
            [
                'ownerId' => 0,
                'group' => StreamPumpConfig::CONFIG_GROUP,
                'setting' => StreamPumpConfig::KEY_PERCENT,
                'value' => '0',
            ],
        ]);
    }
}
