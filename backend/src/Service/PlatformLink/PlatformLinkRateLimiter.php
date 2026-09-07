<?php

declare(strict_types=1);

namespace App\Service\PlatformLink;

use App\Service\Infrastructure\RedisService;

final readonly class PlatformLinkRateLimiter
{
    public function __construct(
        private RedisService $redis,
    ) {
    }

    public function allow(string $key, int $limit, int $windowSeconds): bool
    {
        $count = $this->redis->increment($key);
        if (1 === $count) {
            $this->redis->expire($key, $windowSeconds);
        }

        return null === $count || $count <= $limit;
    }
}
