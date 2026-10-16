<?php

declare(strict_types=1);

namespace App\Seed;

use App\Service\MarketingNews\MarketingNewsConfig;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Idempotent seeder for global marketing news config (BCONFIG, ownerId=0).
 *
 * Master switch is seeded OFF so partner/self-hosted/OSS installs never show
 * Synaplan marketing until an admin opts in via System Config. Insert-if-missing
 * only — operator overrides are never touched.
 */
#[AsTaggedItem(priority: 240)]
final readonly class MarketingNewsConfigSeeder implements SeederInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function label(): string
    {
        return 'marketing-news';
    }

    public function description(): string
    {
        return 'BCONFIG, group=MARKETING_NEWS, ownerId=0 — master switch OFF';
    }

    public function seed(): SeedResult
    {
        $rows = [
            [
                'ownerId' => 0,
                'group' => MarketingNewsConfig::CONFIG_GROUP,
                'setting' => MarketingNewsConfig::KEY_ENABLED,
                'value' => '0',
            ],
            [
                'ownerId' => 0,
                'group' => MarketingNewsConfig::CONFIG_GROUP,
                'setting' => MarketingNewsConfig::KEY_FEED_URL_EN,
                'value' => MarketingNewsConfig::DEFAULT_FEED_URL_EN,
            ],
            [
                'ownerId' => 0,
                'group' => MarketingNewsConfig::CONFIG_GROUP,
                'setting' => MarketingNewsConfig::KEY_FEED_URL_DE,
                'value' => MarketingNewsConfig::DEFAULT_FEED_URL_DE,
            ],
            [
                'ownerId' => 0,
                'group' => MarketingNewsConfig::CONFIG_GROUP,
                'setting' => MarketingNewsConfig::KEY_FEED_URL_DEFAULT,
                'value' => MarketingNewsConfig::DEFAULT_FEED_URL_EN,
            ],
        ];

        return BConfigSeeder::insertIfMissing($this->connection, 'marketing_news_config', $rows);
    }
}
