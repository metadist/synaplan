<?php

declare(strict_types=1);

namespace App\Service\Admin\SystemConfig\Tab;

use App\Service\Admin\SystemConfig\ConfigSection;
use App\Service\Admin\SystemConfig\DatabaseRow;
use App\Service\Admin\SystemConfig\FieldDefinition;
use App\Service\Admin\SystemConfig\SystemConfigTab;
use App\Service\MarketingNews\MarketingNewsConfig;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The Guest Landing tab: the marketing news on the guest landing page, a
 * master switch and per-locale RSS feed URLs in BCONFIG group MARKETING_NEWS.
 * Seeded off; the feed URLs have no effect until the switch is on.
 */
#[AsTaggedItem(priority: 20)]
final readonly class GuestLandingTab implements SystemConfigTab
{
    public function id(): string
    {
        return 'guest_landing';
    }

    public function label(): string
    {
        return 'Guest Landing';
    }

    public function sections(): array
    {
        return [
            'marketing_news' => $this->marketingNews(),
        ];
    }

    private function marketingNews(): ConfigSection
    {
        return new ConfigSection('Marketing News', [
            'MARKETING_NEWS_ENABLED' => new FieldDefinition(
                type: 'boolean',
                description: 'Show marketing news on the guest landing. When enabled, anonymous visitors see a news card grid on the empty chat screen (fetched from the feed URLs below). When disabled, the landing shows only the welcome text and no feed is fetched. Off by default on every installation.',
                default: 'false',
                row: new DatabaseRow(MarketingNewsConfig::CONFIG_GROUP, MarketingNewsConfig::KEY_ENABLED),
            ),
            'MARKETING_NEWS_FEED_URL_EN' => new FieldDefinition(
                type: 'url',
                description: 'RSS/WordPress feed URL for English-speaking visitors. Ignored while the master switch is off.',
                default: MarketingNewsConfig::DEFAULT_FEED_URL_EN,
                row: new DatabaseRow(MarketingNewsConfig::CONFIG_GROUP, MarketingNewsConfig::KEY_FEED_URL_EN),
            ),
            'MARKETING_NEWS_FEED_URL_DE' => new FieldDefinition(
                type: 'url',
                description: 'RSS/WordPress feed URL for German-speaking visitors. Ignored while the master switch is off.',
                default: MarketingNewsConfig::DEFAULT_FEED_URL_DE,
                row: new DatabaseRow(MarketingNewsConfig::CONFIG_GROUP, MarketingNewsConfig::KEY_FEED_URL_DE),
            ),
            'MARKETING_NEWS_FEED_URL_DEFAULT' => new FieldDefinition(
                type: 'url',
                description: 'Fallback RSS/WordPress feed URL for all other languages (e.g. ES, TR). Ignored while the master switch is off.',
                default: MarketingNewsConfig::DEFAULT_FEED_URL_EN,
                row: new DatabaseRow(MarketingNewsConfig::CONFIG_GROUP, MarketingNewsConfig::KEY_FEED_URL_DEFAULT),
            ),
        ]);
    }
}
