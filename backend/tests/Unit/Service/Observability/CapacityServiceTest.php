<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Observability;

use App\Observability\ServerIdentity;
use App\Service\Infrastructure\RedisService;
use App\Service\Observability\CapacityService;
use App\Service\Observability\CapacityUnavailableException;
use PHPUnit\Framework\TestCase;

final class CapacityServiceTest extends TestCase
{
    public function testRedisDownIsUnavailableNotEmpty(): void
    {
        $redis = $this->createStub(RedisService::class);
        $redis->method('hGetAll')->willReturn(null);

        $this->expectException(CapacityUnavailableException::class);
        (new CapacityService($redis, new ServerIdentity('web2')))->report();
    }

    public function testNoServerYetIsAnEmptyListWithTheAnsweringServer(): void
    {
        $redis = $this->createStub(RedisService::class);
        $redis->method('hGetAll')->willReturn([]);

        $report = (new CapacityService($redis, new ServerIdentity('web2')))->report();

        self::assertSame('web2', $report['served_by']);
        self::assertSame([], $report['servers']);
    }

    public function testALiveSnapshotAndASilentServerAreBothListed(): void
    {
        $fresh = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
        $redis = $this->createStub(RedisService::class);
        $redis->method('hGetAll')->willReturn([
            'web2' => $fresh,
            'web1' => '2026-10-24T18:00:00Z',
        ]);
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd');
        $redis->method('get')->willReturnCallback(static fn (string $key): ?string => match ($key) {
            'capacity:peak:web2:'.$today => json_encode(['day' => $today, 'streams_busy_max' => 14, 'streams_total' => 16, 'at' => '2026-10-24T11:40:05Z'], JSON_THROW_ON_ERROR),
            'capacity:server:web2' => json_encode([
                'server_id' => 'web2',
                'hostname' => '79e4f90bc8c6',
                'role' => 'web',
                'runtime' => 'compose',
                'version' => '5.6.0',
                'watchdog_version' => '0.1.0',
                'updated_at' => $fresh,
                'php_ready' => true,
                'admin_ok' => true,
                'streams' => ['total' => 16, 'busy' => 4],
                'api' => ['total' => 8, 'busy' => 1],
                'memory_rss_bytes' => 491044864,
                'memory_limit_bytes' => 0,
                'memory_limited' => false,
                'cpu_cores' => 10,
                'cpu_limited' => false,
                'cpu_usage_ratio' => 0.14,
                'place' => ['host' => 'synweb101'],
                'logs' => ['collector' => 'docker', 'event_ring_host' => '79e4f90bc8c6'],
                'threads' => ['count' => 24, 'busy' => 5],
            ], JSON_THROW_ON_ERROR),
            default => null,
        });

        $report = (new CapacityService($redis, new ServerIdentity('web2')))->report();

        self::assertSame(['web1', 'web2'], array_column($report['servers'], 'id'));

        $silent = $report['servers'][0];
        self::assertFalse($silent['reporting']);
        self::assertSame('2026-10-24T18:00:00Z', $silent['last_seen_at']);
        self::assertSame('2026-10-24T18:00:00Z', $silent['updated_at']);
        self::assertSame(['total' => 0, 'busy' => 0], $silent['streams']);

        $live = $report['servers'][1];
        self::assertTrue($live['reporting']);
        self::assertSame('web', $live['role']);
        self::assertSame(['total' => 16, 'busy' => 4], $live['streams']);
        self::assertSame(['rss_bytes' => 491044864, 'limit_bytes' => 0, 'limited' => false], $live['memory']);
        self::assertSame(10.0, $live['cpu']['cores']);
        self::assertSame(0.14, $live['cpu']['usage_ratio']);
        self::assertSame('synweb101', $live['place']['host']);
        self::assertSame('', $live['place']['pod']);
        self::assertSame('docker', $live['logs']['collector']);
        self::assertSame(['count' => 24, 'busy' => 5], $live['threads']);
        self::assertSame(['streams_busy' => 14, 'streams_total' => 16, 'at' => '2026-10-24T11:40:05Z'], $live['peak_today']);
        self::assertSame(['streams_busy' => 0, 'streams_total' => 0, 'at' => null], $silent['peak_today']);
    }

    public function testAStaleSnapshotIsNotReporting(): void
    {
        $redis = $this->createStub(RedisService::class);
        $redis->method('hGetAll')->willReturn(['web1' => '2026-10-24T18:00:00Z']);
        $redis->method('get')->willReturn(json_encode(['updated_at' => '2026-10-24T18:00:00Z', 'role' => 'worker'], JSON_THROW_ON_ERROR));

        $report = (new CapacityService($redis, new ServerIdentity(null)))->report();

        self::assertFalse($report['servers'][0]['reporting']);
        self::assertSame('worker', $report['servers'][0]['role']);
        self::assertNull($report['servers'][0]['cpu']['usage_ratio']);
    }
}
