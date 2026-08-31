<?php

declare(strict_types=1);

namespace App\Tests\Unit\Monolog;

use App\Monolog\EventRingHandler;
use App\Observability\EventRingStore;
use App\Observability\EventScrubber;
use App\Service\Infrastructure\RedisService;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Regression guard: an unreachable Redis must not turn a single log call into
 * unbounded recursion.
 *
 * The failure mode is real. {@see RedisService} catches connection errors and
 * logs "Redis command failed" as a warning through the SAME logger the
 * {@see EventRingHandler} is attached to. Without the handler's reentrancy
 * guard, that warning re-enters the handler, hits Redis again, logs again — and
 * recurses until the stack overflows, precisely during a Redis outage.
 */
final class EventRingHandlerReentrancyTest extends TestCase
{
    public function testUnreachableRedisDoesNotRecurse(): void
    {
        // A logger the RedisService will use to report its own failures.
        $logger = new Logger('test');

        // Port with nothing listening → every command throws a connection error,
        // which RedisService swallows and logs back through $logger.
        $deadRedis = new RedisService('redis://127.0.0.1:6390', 'test', $logger);
        $store = new EventRingStore($deadRedis, new EventScrubber());
        $handler = new EventRingHandler($store, new RequestStack());
        $logger->pushHandler($handler);

        // Without the guard this recurses until PHP dies. With it, the reentrant
        // "Redis command failed" warning is suppressed and the call returns.
        $logger->log(Level::Error, 'something failed in prod');

        // Reaching this line at all is the assertion: no stack overflow, no throw.
        $this->addToAssertionCount(1);
    }
}
