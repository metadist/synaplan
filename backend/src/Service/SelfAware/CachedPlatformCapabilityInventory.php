<?php

declare(strict_types=1);

namespace App\Service\SelfAware;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * 5-minute per-user cache in front of {@see PlatformCapabilityInventory}.
 *
 * Key: `selfaware.inventory.{userId}.{isAdmin}.{epoch}`. A global
 * {@see forget()} bumps the epoch so every cached report expires (provider
 * keys and default-model saves).
 */
#[AsAlias(id: CapabilityInventory::class, public: true)]
final readonly class CachedPlatformCapabilityInventory implements CapabilityInventory
{
    private const TTL_SECONDS = 300;
    private const EPOCH_KEY = 'selfaware.inventory.epoch';

    public function __construct(
        private PlatformCapabilityInventory $inner,
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function build(int $userId): CapabilityReport
    {
        $key = $this->itemKey($userId);
        $item = $this->cache->getItem($key);
        if ($item->isHit() && $item->get() instanceof CapabilityReport) {
            return $item->get();
        }

        $probe = $this->inner->build($userId);
        $item->set($probe);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);

        return $probe;
    }

    public function forget(?int $userId = null): void
    {
        if (null !== $userId) {
            $this->cache->deleteItem($this->itemKey($userId));

            return;
        }

        $item = $this->cache->getItem(self::EPOCH_KEY);
        $epoch = is_int($item->get()) ? $item->get() : 0;
        $item->set($epoch + 1);
        $this->cache->save($item);
    }

    private function itemKey(int $userId): string
    {
        $epochItem = $this->cache->getItem(self::EPOCH_KEY);
        $epoch = is_int($epochItem->get()) ? $epochItem->get() : 0;

        return sprintf('selfaware.inventory.%d.%d', $userId, $epoch);
    }
}
