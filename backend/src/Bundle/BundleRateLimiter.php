<?php

declare(strict_types=1);

namespace App\Bundle;

use Psr\Cache\CacheItemPoolInterface;

final readonly class BundleRateLimiter
{
    public const ACTION_EXPORT = 'bundle_export';
    public const ACTION_IMPORT = 'bundle_import';

    private const LIMITS = [
        self::ACTION_EXPORT => 20,
        self::ACTION_IMPORT => 10,
    ];

    public function __construct(
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function consume(int $userId, string $action): bool
    {
        $limit = self::LIMITS[$action] ?? 10;
        $hour = (string) intdiv(time(), 3600);
        $key = sprintf('bundle_rl_%s_%d_%s', $action, $userId, $hour);
        $item = $this->cache->getItem($key);
        $used = is_int($item->get()) ? $item->get() : 0;
        if ($used >= $limit) {
            return false;
        }
        $item->set($used + 1);
        $item->expiresAfter(3700);
        $this->cache->save($item);

        return true;
    }
}
