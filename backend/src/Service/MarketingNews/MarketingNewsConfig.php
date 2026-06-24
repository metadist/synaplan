<?php

declare(strict_types=1);

namespace App\Service\MarketingNews;

use App\Repository\ConfigRepository;

/**
 * Admin-toggleable marketing news feed configuration (BCONFIG group MARKETING_NEWS).
 *
 * Single global master switch (ENABLED) gates the whole feature; when off, no feed
 * URL is ever resolved and no outbound HTTP happens. Self-hosted/OSS installs ship
 * with ENABLED='0' and opt in via the admin System Config panel.
 *
 * Feed URLs are resolved by UI locale: de -> FEED_URL_DE, en -> FEED_URL_EN,
 * all other locales -> FEED_URL_DEFAULT.
 */
final readonly class MarketingNewsConfig
{
    public const CONFIG_GROUP = 'MARKETING_NEWS';

    public const KEY_ENABLED = 'ENABLED';
    public const KEY_FEED_URL_DE = 'FEED_URL_DE';
    public const KEY_FEED_URL_EN = 'FEED_URL_EN';
    public const KEY_FEED_URL_DEFAULT = 'FEED_URL_DEFAULT';

    public const DEFAULT_FEED_URL_EN = 'https://www.synaplan.com/feed.xml';
    public const DEFAULT_FEED_URL_DE = 'https://www.synaplan.com/de/feed.xml';

    public function __construct(
        private ConfigRepository $configRepository,
    ) {
    }

    /**
     * Master switch. Global-only (ownerId=0); defaults OFF when no row exists.
     *
     * Accepts both the seeder convention ('1'/'0') and the admin UI convention
     * ('true'/'false'), like {@see MultitaskRoutingConfig}.
     */
    public function isEnabled(): bool
    {
        $value = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_ENABLED);
        if (null === $value) {
            return false;
        }

        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? false;
    }

    /**
     * Resolve the feed URL for a UI locale, or null when the feature is disabled
     * or no valid http(s) URL is configured.
     */
    public function resolveFeedUrl(string $locale): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $normalized = strtolower(trim($locale));
        $setting = match ($normalized) {
            'de' => self::KEY_FEED_URL_DE,
            'en' => self::KEY_FEED_URL_EN,
            default => self::KEY_FEED_URL_DEFAULT,
        };

        $default = match ($normalized) {
            'de' => self::DEFAULT_FEED_URL_DE,
            default => self::DEFAULT_FEED_URL_EN,
        };

        $raw = $this->configRepository->getValue(0, self::CONFIG_GROUP, $setting);
        $url = null !== $raw && '' !== trim($raw) ? trim($raw) : $default;

        return $this->isValidFeedUrl($url) ? $url : null;
    }

    private function isValidFeedUrl(string $url): bool
    {
        if (!filter_var($url, \FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = parse_url($url, \PHP_URL_SCHEME);

        return \in_array($scheme, ['http', 'https'], true);
    }
}
