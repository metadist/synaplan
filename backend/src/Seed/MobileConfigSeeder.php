<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\Client\MobileVersionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Idempotent seeder for the mobile forced-update gate config (BCONFIG, ownerId=0).
 *
 * Seeds explicit global rows so the min-version + store links are visible and
 * editable in the admin System Config UI. Insert-if-missing only — operator
 * overrides are never touched. Defaults leave the gate OFF (empty min-version),
 * so seeding never blocks an existing install.
 */
#[AsTaggedItem(priority: 250)]
final readonly class MobileConfigSeeder implements SeederInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function label(): string
    {
        return 'mobile';
    }

    public function description(): string
    {
        return 'BCONFIG, group=MOBILE, ownerId=0';
    }

    public function seed(): SeedResult
    {
        $g = MobileVersionService::GROUP;
        $rows = [
            ['ownerId' => 0, 'group' => $g, 'setting' => MobileVersionService::KEY_MIN_APP_VERSION, 'value' => MobileVersionService::DEFAULT_MIN_APP_VERSION],
            ['ownerId' => 0, 'group' => $g, 'setting' => MobileVersionService::KEY_UPDATE_ENFORCE_AFTER, 'value' => MobileVersionService::DEFAULT_UPDATE_ENFORCE_AFTER],
            ['ownerId' => 0, 'group' => $g, 'setting' => MobileVersionService::KEY_IOS_APP_URL,     'value' => MobileVersionService::DEFAULT_IOS_APP_URL],
            ['ownerId' => 0, 'group' => $g, 'setting' => MobileVersionService::KEY_ANDROID_APP_URL, 'value' => MobileVersionService::DEFAULT_ANDROID_APP_URL],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'mobile_config', $rows);
    }
}
