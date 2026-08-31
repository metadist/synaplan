<?php

declare(strict_types=1);

namespace App\Tests\Unit\Monolog;

use App\Monolog\EventRingHandler;
use App\Observability\EventRingStore;
use App\Observability\EventScrubber;
use App\Service\Infrastructure\RedisService;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Regression guards for the behaviour of the ring handler while Redis is down.
 *
 * The failure mode is real. {@see RedisService} catches connection errors and
 * logs "Redis command failed" as a warning through the SAME logger the
 * {@see EventRingHandler} is attached to, so a naive handler both recurses and
 * pays the Predis connection timeout on every single log call — precisely
 * during an outage, when logging must stay cheap and safe.
 */
final class EventRingHandlerReentrancyTest extends TestCase
{
    /** Port with nothing listening → every Redis command throws immediately. */
    private const DEAD_REDIS_DSN = 'redis://127.0.0.1:6390';

    public function testUnreachableRedisDoesNotRecurse(): void
    {
        $logger = new Logger('test');
        $logger->pushHandler($this->ringHandler($logger));

        // Without the reentrancy guard this recurses until PHP dies. With it,
        // the reentrant "Redis command failed" warning is suppressed.
        $logger->log(Level::Error, 'something failed in prod');

        // Reaching this line at all is the assertion: no stack overflow, no throw.
        $this->addToAssertionCount(1);
    }

    /**
     * After a refused write the handler must stop calling Redis for a while.
     * Otherwise every logged warning during an outage pays the connection
     * timeout again, turning a Redis incident into an app-wide slowdown.
     */
    public function testStopsCallingRedisAfterAFailedWrite(): void
    {
        $logger = new Logger('test');
        $spy = new TestHandler();
        $logger->pushHandler($spy);
        $logger->pushHandler($this->ringHandler($logger));

        $logger->log(Level::Error, 'first failure');
        self::assertTrue(
            $spy->hasRecordThatContains('Redis command failed', Level::Warning),
            'The first event should have attempted a Redis write.',
        );

        $spy->clear();
        $logger->log(Level::Error, 'second failure');

        self::assertTrue($spy->hasRecordThatContains('second failure', Level::Error));
        self::assertFalse(
            $spy->hasRecordThatContains('Redis command failed', Level::Warning),
            'The handler should stay muted after the first refused write.',
        );
    }

    private function ringHandler(Logger $logger): EventRingHandler
    {
        $store = new EventRingStore(
            new RedisService(self::DEAD_REDIS_DSN, 'test', $logger),
            new EventScrubber(),
        );

        return new EventRingHandler($store, new RequestStack());
    }
}
