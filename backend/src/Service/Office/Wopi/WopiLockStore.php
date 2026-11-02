<?php

declare(strict_types=1);

namespace App\Service\Office\Wopi;

use Psr\Cache\CacheItemPoolInterface;

/**
 * The WOPI lock Collabora holds while a document is open.
 *
 * The cache is the shared store (Redis in compose), so two PHP workers agree
 * on the lock. The item expires if a client never unlocks.
 */
final readonly class WopiLockStore
{
    private const TTL_SECONDS = 1800;

    public function __construct(
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function current(int $fileId): string
    {
        $item = $this->cache->getItem($this->key($fileId));
        if (!$item->isHit()) {
            return '';
        }
        $value = $item->get();

        return is_string($value) ? $value : '';
    }

    /**
     * @return array{ok: bool, current: string}
     */
    public function lock(int $fileId, string $lock): array
    {
        $lock = trim($lock);
        $current = $this->current($fileId);
        if ('' === $lock) {
            return ['ok' => false, 'current' => $current];
        }
        if ('' !== $current && !hash_equals($current, $lock)) {
            return ['ok' => false, 'current' => $current];
        }
        $this->store($fileId, $lock);

        return ['ok' => true, 'current' => $lock];
    }

    /**
     * @return array{ok: bool, current: string}
     */
    public function unlock(int $fileId, string $lock): array
    {
        $current = $this->current($fileId);
        if ('' === $current || !hash_equals($current, trim($lock))) {
            return ['ok' => false, 'current' => $current];
        }
        $this->cache->deleteItem($this->key($fileId));

        return ['ok' => true, 'current' => ''];
    }

    /**
     * @return array{ok: bool, current: string}
     */
    public function refresh(int $fileId, string $lock): array
    {
        $current = $this->current($fileId);
        if ('' === $current || !hash_equals($current, trim($lock))) {
            return ['ok' => false, 'current' => $current];
        }
        $this->store($fileId, $current);

        return ['ok' => true, 'current' => $current];
    }

    /**
     * @return array{ok: bool, current: string}
     */
    public function relock(int $fileId, string $oldLock, string $newLock): array
    {
        $current = $this->current($fileId);
        $newLock = trim($newLock);
        if ('' === $newLock || '' === $current || !hash_equals($current, trim($oldLock))) {
            return ['ok' => false, 'current' => $current];
        }
        $this->store($fileId, $newLock);

        return ['ok' => true, 'current' => $newLock];
    }

    private function store(int $fileId, string $lock): void
    {
        $item = $this->cache->getItem($this->key($fileId));
        $item->set($lock);
        $item->expiresAfter(self::TTL_SECONDS);
        $this->cache->save($item);
    }

    private function key(int $fileId): string
    {
        return 'wopi.lock.'.$fileId;
    }
}
