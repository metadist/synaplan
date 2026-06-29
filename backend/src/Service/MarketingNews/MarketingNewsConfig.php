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

    /**
     * Hosts allowed for the cover-image proxy: the hosts of every configured
     * feed URL (stored value, or built-in default when unset). Used as an
     * SSRF allowlist so the proxy can only fetch images from the same origins
     * we already trust for feeds.
     *
     * @return list<string>
     */
    public function allowedImageHosts(): array
    {
        $settings = [
            self::KEY_FEED_URL_EN => self::DEFAULT_FEED_URL_EN,
            self::KEY_FEED_URL_DE => self::DEFAULT_FEED_URL_DE,
            self::KEY_FEED_URL_DEFAULT => self::DEFAULT_FEED_URL_EN,
        ];

        $hosts = [];
        foreach ($settings as $setting => $default) {
            $raw = $this->configRepository->getValue(0, self::CONFIG_GROUP, $setting);
            $url = null !== $raw && '' !== trim($raw) ? trim($raw) : $default;
            $host = parse_url($url, \PHP_URL_HOST);
            if (\is_string($host) && '' !== $host) {
                $hosts[] = strtolower($host);
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * Whether the proxy may fetch this image URL: feature enabled, http(s),
     * and the host is one of the configured feed hosts.
     */
    public function isAllowedImageUrl(string $url): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        if (!$this->isValidFeedUrl($url)) {
            return false;
        }

        $host = parse_url($url, \PHP_URL_HOST);
        if (!\is_string($host) || '' === $host) {
            return false;
        }

        return \in_array(strtolower($host), $this->allowedImageHosts(), true);
    }

    private function isValidFeedUrl(string $url): bool
    {
        if (!filter_var($url, \FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = parse_url($url, \PHP_URL_SCHEME);
        if (!\in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = parse_url($url, \PHP_URL_HOST);
        if (!\is_string($host) || '' === $host) {
            return false;
        }

        // SSRF guard: the feed fetch and the PUBLIC image proxy both fetch these
        // hosts server-side, so reject hosts that are not routable from the
        // public internet (loopback, RFC 1918 / RFC 4193, link-local, and
        // non-public TLDs). Mirrors MediaGenerationHandler::isPublicBaseUrlReachable().
        return $this->isPubliclyRoutableHost($host);
    }

    private function isPubliclyRoutableHost(string $host): bool
    {
        // Normalise an IPv6 literal ("[::1]" → "::1") before inspection.
        $host = trim($host, '[]');
        $lowerHost = strtolower($host);

        if ('localhost' === $lowerHost || 'host.docker.internal' === $lowerHost) {
            return false;
        }
        foreach (['.local', '.localhost', '.internal', '.lan', '.home', '.test'] as $suffix) {
            if (str_ends_with($lowerHost, $suffix)) {
                return false;
            }
        }

        // IP literal: reject private + reserved ranges (10.x, 172.16–31.x,
        // 192.168.x, 169.254.x, 127.x, ::1, fc00::/7, …).
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            return false !== filter_var(
                $host,
                \FILTER_VALIDATE_IP,
                \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE,
            );
        }

        // A non-IP hostname that isn't one of the local cases above is assumed
        // to resolve publicly (a real DNS name behind a proxy/CDN).
        return true;
    }
}
