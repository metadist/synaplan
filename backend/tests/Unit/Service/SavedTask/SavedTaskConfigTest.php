<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SavedTask;

use App\Repository\ConfigRepository;
use App\Service\SavedTask\SavedTaskConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SavedTaskConfigTest extends TestCase
{
    private ConfigRepository&MockObject $configRepository;
    private SavedTaskConfig $config;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->config = new SavedTaskConfig($this->configRepository);
    }

    public function testDefaultsOffWhenNoRowsExist(): void
    {
        $this->configRepository->method('getValue')->willReturn(null);

        self::assertFalse($this->config->isEnabled(42));
        self::assertFalse($this->config->isEnabled(null));
    }

    public function testGlobalRowOverridesBuiltInDefault(): void
    {
        $this->configRepository->method('getValue')
            ->willReturnCallback(static function (int $ownerId): ?string {
                return 0 === $ownerId ? '1' : null;
            });

        self::assertTrue($this->config->isEnabled(42));
    }

    public function testPerUserRowOverridesGlobal(): void
    {
        $this->configRepository->method('getValue')
            ->willReturnCallback(static function (int $ownerId): string {
                return 0 === $ownerId ? '1' : '0';
            });

        self::assertFalse($this->config->isEnabled(7));
    }

    public function testPerUserOnBeatsGlobalOff(): void
    {
        $this->configRepository->method('getValue')
            ->willReturnCallback(static function (int $ownerId): string {
                return 0 === $ownerId ? '0' : '1';
            });

        self::assertTrue($this->config->isEnabled(7));
    }

    public function testAnonymousUserIgnoresPerUserLookup(): void
    {
        $this->configRepository->expects(self::once())
            ->method('getValue')
            ->with(0, SavedTaskConfig::CONFIG_GROUP, SavedTaskConfig::KEY_ENABLED)
            ->willReturn('1');

        self::assertTrue($this->config->isEnabled(null));
    }

    public function testMalformedValueFallsBackToDefaultOff(): void
    {
        $this->configRepository->method('getValue')
            ->willReturnCallback(static function (int $ownerId): ?string {
                return 0 === $ownerId ? 'not-a-bool' : null;
            });

        self::assertFalse($this->config->isEnabled(1));
    }
}
