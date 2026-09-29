<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Telegram sends an album as one update per photo. The parts are collected
 * here so the album becomes one chat turn. A part that arrives after the
 * turn started is answered on its own instead of being lost.
 */
final readonly class TelegramAlbumBuffer
{
    private const TTL_SECONDS = 600;
    private const LOCK_SECONDS = 10.0;
    private const MAX_PARTS = 10;

    public function __construct(
        private CacheItemPoolInterface $cache,
        private LockFactory $lockFactory,
    ) {
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return bool|null true for the first part, false for a later one,
     *                   null when the album was already answered
     */
    public function add(int $botRowId, string $groupId, int $updateId, array $message): ?bool
    {
        $lock = $this->lockFactory->createLock($this->key($botRowId, $groupId).'_lock', self::LOCK_SECONDS);
        $lock->acquire(true);
        try {
            $item = $this->cache->getItem($this->key($botRowId, $groupId));
            $state = $this->state($item->get());
            if ($state['flushed'] || count($state['parts']) >= self::MAX_PARTS) {
                return null;
            }
            $first = [] === $state['parts'];
            $state['parts'][(string) $updateId] = $message;
            $item->set($state);
            $item->expiresAfter(self::TTL_SECONDS);
            $this->cache->save($item);

            return $first;
        } finally {
            $lock->release();
        }
    }

    /**
     * Closes the album and returns its parts in the order they were sent,
     * each with the update id it arrived in.
     *
     * @return array<int, array<string, mixed>>
     */
    public function take(int $botRowId, string $groupId): array
    {
        $lock = $this->lockFactory->createLock($this->key($botRowId, $groupId).'_lock', self::LOCK_SECONDS);
        $lock->acquire(true);
        try {
            $item = $this->cache->getItem($this->key($botRowId, $groupId));
            $state = $this->state($item->get());
            if ($state['flushed']) {
                return [];
            }
            $item->set(['flushed' => true, 'parts' => []]);
            $item->expiresAfter(self::TTL_SECONDS);
            $this->cache->save($item);

            $parts = [];
            foreach ($state['parts'] as $updateId => $message) {
                $parts[(int) $updateId] = $message;
            }
            uasort($parts, static fn (array $a, array $b): int => (int) ($a['message_id'] ?? 0) <=> (int) ($b['message_id'] ?? 0));

            return $parts;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{flushed: bool, parts: array<string, array<string, mixed>>}
     */
    private function state(mixed $value): array
    {
        if (!is_array($value)) {
            return ['flushed' => false, 'parts' => []];
        }
        $parts = [];
        foreach (is_array($value['parts'] ?? null) ? $value['parts'] : [] as $updateId => $message) {
            if (is_array($message)) {
                $parts[(string) $updateId] = $message;
            }
        }

        return ['flushed' => !empty($value['flushed']), 'parts' => $parts];
    }

    private function key(int $botRowId, string $groupId): string
    {
        return 'telegram_album_'.$botRowId.'_'.hash('sha256', $groupId);
    }
}
