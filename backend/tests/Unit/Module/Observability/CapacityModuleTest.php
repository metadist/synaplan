<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Observability;

use App\Module\Observability\CapacityModule;
use App\Service\Observability\CapacityService;
use App\Service\Observability\CapacityUnavailableException;
use PHPUnit\Framework\TestCase;

final class CapacityModuleTest extends TestCase
{
    public function testOnlyAnExplicitOffTurnsTheModuleOff(): void
    {
        $service = $this->createStub(CapacityService::class);

        self::assertTrue((new CapacityModule($service, '1'))->isConfigured());
        self::assertTrue((new CapacityModule($service, ''))->isConfigured(), 'unset reads as on, like the entrypoint');
        self::assertFalse((new CapacityModule($service, '0'))->isConfigured());
        self::assertFalse((new CapacityModule($service, 'false'))->isConfigured());
        self::assertFalse((new CapacityModule($service, 'OFF'))->isConfigured());
    }

    public function testStatusCountsReportingServers(): void
    {
        $service = $this->createStub(CapacityService::class);
        $service->method('report')->willReturn([
            'served_by' => 'web1',
            'generated_at' => '2026-10-24T19:00:00+00:00',
            'servers' => [
                $this->server('web1', true),
                $this->server('web2', false),
            ],
        ]);

        $status = (new CapacityModule($service, '1'))->status();

        self::assertTrue($status->healthy);
        self::assertSame('1 of 2 servers reporting', $status->message);
        self::assertSame(['servers' => 2, 'reporting' => 1], $status->details);
    }

    public function testStatusWithoutRedisIsUnhealthyNotAbsent(): void
    {
        $service = $this->createStub(CapacityService::class);
        $service->method('report')->willThrowException(CapacityUnavailableException::redisDown());

        $status = (new CapacityModule($service, '1'))->status();

        self::assertTrue($status->configured);
        self::assertFalse($status->healthy);
        self::assertSame('needs_setup', $status->state());
    }

    public function testOffIsAbsent(): void
    {
        $status = (new CapacityModule($this->createStub(CapacityService::class), '0'))->status();

        self::assertSame('absent', $status->state());
    }

    /**
     * @return array<string, mixed>
     */
    private function server(string $id, bool $reporting): array
    {
        return [
            'id' => $id,
            'hostname' => $id,
            'role' => 'web',
            'runtime' => 'compose',
            'version' => 'dev',
            'watchdog_version' => '0.1.0',
            'reporting' => $reporting,
            'updated_at' => '2026-10-24T19:00:00Z',
            'last_seen_at' => '2026-10-24T19:00:00Z',
            'php_ready' => $reporting,
            'admin_ok' => $reporting,
            'streams' => ['total' => 0, 'busy' => 0],
            'api' => ['total' => 0, 'busy' => 0],
            'memory' => ['rss_bytes' => 0, 'limit_bytes' => 0, 'limited' => false],
            'cpu' => ['cores' => 0.0, 'limited' => false, 'usage_ratio' => null],
            'place' => ['host' => '', 'address' => '', 'pod' => '', 'namespace' => '', 'deployment' => '', 'container' => '', 'cpu_request' => '', 'memory_limit' => ''],
            'logs' => ['collector' => '', 'event_ring_host' => ''],
            'threads' => ['count' => 0, 'busy' => 0],
            'peak_today' => ['streams_busy' => 0, 'streams_total' => 0, 'at' => null],
        ];
    }
}
