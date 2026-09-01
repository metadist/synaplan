<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Chat\Run\ChatRunBuffer;
use App\Service\Chat\Run\ChatRunService;
use App\Service\Infrastructure\RedisService;
use Psr\Log\NullLogger;

/**
 * Builds a REAL {@see ChatRunService} over an unreachable Redis for DB-free
 * unit tests. The class is final (so it cannot be mocked) and its Redis layer
 * degrades to no-ops rather than throwing, so `begin()` simply returns null:
 * the turn streams without resume support, exactly as it does in production
 * when Redis is down. That is precisely the neutral behaviour a controller
 * unit test wants.
 */
final class ChatRunServiceFactory
{
    public static function withoutRedis(): ChatRunService
    {
        return new ChatRunService(
            new ChatRunBuffer(new RedisService('', 'test', new NullLogger())),
            new NullLogger(),
        );
    }
}
