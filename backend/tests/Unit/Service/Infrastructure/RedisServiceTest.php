<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Infrastructure;

use App\Service\Infrastructure\RedisService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Behavioural tests for {@see RedisService}.
 *
 * We can exercise the failure paths without a running Redis by passing an
 * empty DSN — every public command should degrade to a null/false return
 * value and every connection error should be observable via
 * {@see RedisService::getLastConnectionError()}. This ensures features that
 * sit on top of Redis stay tolerant of an unavailable server (per
 * AGENTS-DEV: degrade gracefully, never bubble infra errors up to the user).
 */
final class RedisServiceTest extends TestCase
{
    public function testPingReturnsFalseWhenDsnIsEmpty(): void
    {
        $service = $this->buildWithEmptyDsn();

        $this->assertFalse($service->ping());
        $this->assertFalse($service->isAvailable());
        $error = $service->getLastConnectionError();
        $this->assertNotNull($error);
        $this->assertSame('REDIS_DSN is empty', $error->getMessage());
    }

    public function testCommandsReturnSentinelValuesWhenUnavailable(): void
    {
        $service = $this->buildWithEmptyDsn();

        $this->assertNull($service->get('foo'));
        $this->assertFalse($service->set('foo', 'bar'));
        $this->assertFalse($service->set('foo', 'bar', 60));
        $this->assertFalse($service->delete('foo'));
        $this->assertFalse($service->exists('foo'));
        $this->assertNull($service->increment('foo'));
        $this->assertFalse($service->expire('foo', 60));
        $this->assertNull($service->publish('chan', 'payload'));
    }

    private function buildWithEmptyDsn(): RedisService
    {
        return new RedisService(
            redisDsn: '',
            environment: 'test',
            logger: $this->createStub(LoggerInterface::class),
        );
    }
}
