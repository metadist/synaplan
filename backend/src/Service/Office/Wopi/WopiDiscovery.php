<?php

declare(strict_types=1);

namespace App\Service\Office\Wopi;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cached Collabora discovery map. A failed fetch is not cached, so the next
 * "Open in editor" tries again.
 */
final readonly class WopiDiscovery
{
    private const CACHE_KEY = 'wopi.discovery.v1';

    private const CACHE_SECONDS = 600;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
        private OfficeEditorConfig $config,
    ) {
    }

    /**
     * @return array<string, array{path: string, writable: bool}>|null null when the editor is off or Collabora did not answer
     */
    public function map(): ?array
    {
        if (!$this->config->isEnabled()) {
            return null;
        }
        $item = $this->cache->getItem(self::CACHE_KEY);
        if ($item->isHit()) {
            $cached = $item->get();
            if (is_array($cached)) {
                /* @var array<string, array{path: string, writable: bool}> $cached */
                return $cached;
            }
        }

        $fetched = $this->fetch();
        if (null === $fetched) {
            return null;
        }
        $item->set($fetched);
        $item->expiresAfter(self::CACHE_SECONDS);
        $this->cache->save($item);

        return $fetched;
    }

    /**
     * @return array{path: string, writable: bool}|null
     */
    public function actionFor(string $extension): ?array
    {
        $map = $this->map();
        if (null === $map) {
            return null;
        }

        return $map[strtolower($extension)] ?? null;
    }

    /**
     * @return array<string, array{path: string, writable: bool}>|null
     */
    private function fetch(): ?array
    {
        $url = $this->config->discoverySource().'/hosting/discovery';
        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 5]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            $this->logger->warning('WOPI discovery fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
        if (200 !== $status || !str_contains($body, 'wopi-discovery')) {
            $this->logger->warning('WOPI discovery was not a discovery document', ['url' => $url, 'status' => $status]);

            return null;
        }
        $map = WopiDiscoveryParser::parse($body);
        if ([] === $map) {
            $this->logger->warning('WOPI discovery contained no editor actions', ['url' => $url]);

            return null;
        }

        return $map;
    }
}
