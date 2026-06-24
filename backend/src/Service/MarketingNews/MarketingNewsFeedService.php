<?php

declare(strict_types=1);

namespace App\Service\MarketingNews;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches, parses and caches marketing news items from a configured RSS feed URL.
 *
 * Returns an empty list whenever the admin master switch is OFF (no outbound HTTP),
 * or whenever fetching/parsing fails, so the landing UI degrades gracefully.
 */
final readonly class MarketingNewsFeedService
{
    private const CACHE_TTL_SECONDS = 1800;
    private const FETCH_TIMEOUT_SECONDS = 5;
    private const DEFAULT_LIMIT = 4;
    private const MAX_LIMIT = 12;

    public function __construct(
        private MarketingNewsConfig $config,
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private RssFeedParser $parser,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<array{title: string, url: string, excerpt: string, imageUrl: string|null, publishedAt: string|null, tags: list<string>}>
     */
    public function getLandingItems(string $locale, int $limit = self::DEFAULT_LIMIT): array
    {
        $feedUrl = $this->config->resolveFeedUrl($locale);
        if (null === $feedUrl) {
            return [];
        }

        $safeLimit = max(1, min(self::MAX_LIMIT, $limit));
        $cacheKey = 'marketing_news_feed_'.hash('sha256', $feedUrl);

        try {
            /** @var list<array{title: string, url: string, excerpt: string, imageUrl: string|null, publishedAt: string|null, tags: list<string>}> $items */
            $items = $this->cache->get($cacheKey, function (ItemInterface $item) use ($feedUrl): array {
                $item->expiresAfter(self::CACHE_TTL_SECONDS);

                return $this->fetchAndParse($feedUrl);
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Marketing news cache/fetch failed', [
                'feedUrl' => $feedUrl,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return \array_slice($items, 0, $safeLimit);
    }

    /**
     * @return list<array{title: string, url: string, excerpt: string, imageUrl: string|null, publishedAt: string|null, tags: list<string>}>
     */
    private function fetchAndParse(string $feedUrl): array
    {
        try {
            $response = $this->httpClient->request('GET', $feedUrl, [
                'headers' => [
                    'Accept' => 'application/rss+xml, application/xml, text/xml, */*',
                    'User-Agent' => 'Synaplan/1.0 (+https://www.synaplan.com)',
                ],
                'timeout' => self::FETCH_TIMEOUT_SECONDS,
            ]);

            if ($response->getStatusCode() >= 400) {
                return [];
            }

            return $this->parser->parse($response->getContent());
        } catch (\Throwable $e) {
            $this->logger->warning('Marketing news feed fetch failed', [
                'feedUrl' => $feedUrl,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
