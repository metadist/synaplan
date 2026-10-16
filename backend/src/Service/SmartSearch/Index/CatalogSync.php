<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Index;

use App\Message\SearchReindexCatalogMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Queues a catalog rebuild once per schema version: a deploy that adds or
 * rewords a setting refreshes the shared rows on the next admin search.
 */
final readonly class CatalogSync
{
    private const KEY_PREFIX = 'smart_search.catalog.';
    private const TTL = 604800;

    public function __construct(
        private SettingsCatalog $catalog,
        private MessageBusInterface $bus,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    public function ensureFresh(): void
    {
        try {
            $this->cache->get(self::KEY_PREFIX.$this->catalog->fingerprint(), function (ItemInterface $item): bool {
                $this->bus->dispatch(new SearchReindexCatalogMessage());
                $item->expiresAfter(self::TTL);

                return true;
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Smart Search catalog sync failed', ['error' => $e->getMessage()]);
        }
    }
}
