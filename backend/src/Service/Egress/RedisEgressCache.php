<?php

declare(strict_types=1);

namespace App\Service\Egress;

use App\Service\Infrastructure\RedisService;

final class RedisEgressCache implements EgressCache
{
    public function __construct(
        private readonly RedisService $redis,
    ) {
    }

    public function put(string $key, string $value, int $ttlSeconds): bool
    {
        return $this->redis->set($key, $value, $ttlSeconds);
    }
}
