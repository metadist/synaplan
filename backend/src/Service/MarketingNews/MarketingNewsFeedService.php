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
    private const MAX_IMAGE_BYTES = 10485760; // 10 MB
    private const IMAGE_PROXY_PATH = '/api/v1/news/image';

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

        $items = \array_slice($items, 0, $safeLimit);

        // Route cover images through our same-origin proxy so cross-origin
        // CORP/hotlink restrictions on the source never break display. The
        // cache holds the ORIGINAL url; we rewrite on output only.
        return array_map(static function (array $item): array {
            if (\is_string($item['imageUrl']) && '' !== $item['imageUrl']) {
                $item['imageUrl'] = self::IMAGE_PROXY_PATH.'?u='.rawurlencode($item['imageUrl']);
            }

            return $item;
        }, $items);
    }

    /**
     * Server-side fetch of a single cover image for the proxy endpoint.
     * Returns null when the URL is not allowed or the fetch fails.
     *
     * @return array{content: string, contentType: string}|null
     */
    public function fetchImage(string $url): ?array
    {
        if (!$this->config->isAllowedImageUrl($url)) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Accept' => 'image/*',
                    'User-Agent' => 'Synaplan/1.0 (+https://www.synaplan.com)',
                ],
                'timeout' => self::FETCH_TIMEOUT_SECONDS,
            ]);

            if ($response->getStatusCode() >= 400) {
                return null;
            }

            $headers = $response->getHeaders(false);

            // Fast reject when the server advertises an oversized payload.
            $contentLength = isset($headers['content-length'][0]) ? (int) $headers['content-length'][0] : 0;
            if ($contentLength > self::MAX_IMAGE_BYTES) {
                return null;
            }

            $contentType = strtolower(trim(explode(';', $headers['content-type'][0] ?? '')[0]));
            if (!str_starts_with($contentType, 'image/')) {
                return null;
            }

            // Stream the body and abort as soon as the cap is exceeded, so a
            // missing/forged Content-Length cannot make us buffer an unbounded
            // payload into memory (DoS guard).
            $content = '';
            foreach ($this->httpClient->stream($response) as $chunk) {
                $content .= $chunk->getContent();
                if (\strlen($content) > self::MAX_IMAGE_BYTES) {
                    $response->cancel();

                    return null;
                }
            }

            return ['content' => $content, 'contentType' => $contentType];
        } catch (\Throwable $e) {
            $this->logger->warning('Marketing news image proxy fetch failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
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
