<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\Repository\ConfigRepository;
use App\Service\Media\MediaJobConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MediaJobConfigTest extends TestCase
{
    private ConfigRepository&MockObject $repo;
    private MediaJobConfig $config;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(ConfigRepository::class);
        $this->config = new MediaJobConfig($this->repo);
    }

    public function testAsyncJobsDisabledByDefault(): void
    {
        $this->repo->method('getValue')->willReturn(null);

        self::assertFalse($this->config->isAsyncJobsEnabled());
        self::assertFalse($this->config->isAsyncJobsEnabled(42));
    }

    public function testPerUserOverrideWinsOverGlobal(): void
    {
        $this->repo->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => 42 === $owner ? '1' : '0'
        );

        self::assertTrue($this->config->isAsyncJobsEnabled(42), 'per-user ON must beat global OFF');
        self::assertFalse($this->config->isAsyncJobsEnabled(99), 'falls through to global OFF');
    }

    public function testGlobalEnableApplies(): void
    {
        $this->repo->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => MediaJobConfig::KEY_ASYNC_JOBS_ENABLED === $setting ? 'true' : null
        );

        self::assertTrue($this->config->isAsyncJobsEnabled());
    }

    public function testDefaultsForNumericSettings(): void
    {
        $this->repo->method('getValue')->willReturn(null);

        self::assertSame(3, $this->config->pollIntervalSeconds());
        self::assertSame(1500, $this->config->imageInlineFastMs());
        self::assertSame(90, $this->config->heartbeatStaleSeconds());
    }

    public function testNumericSettingsAreClamped(): void
    {
        $this->repo->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => match ($setting) {
                MediaJobConfig::KEY_POLL_INTERVAL_SECONDS => '999',
                MediaJobConfig::KEY_IMAGE_INLINE_FAST_MS => '-5',
                MediaJobConfig::KEY_HEARTBEAT_STALE_SECONDS => '1',
                default => null,
            }
        );

        self::assertSame(30, $this->config->pollIntervalSeconds(), 'poll interval clamped to max 30');
        self::assertSame(0, $this->config->imageInlineFastMs(), 'inline window clamped to min 0');
        self::assertSame(30, $this->config->heartbeatStaleSeconds(), 'stale window clamped to min 30');
    }
}
