<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Desktop;

use App\Repository\ConfigRepository;
use App\Service\Desktop\DesktopAgentConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DesktopAgentConfigTest extends TestCase
{
    private ConfigRepository&MockObject $configRepository;
    private DesktopAgentConfig $config;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->config = new DesktopAgentConfig($this->configRepository);
    }

    public function testDefaultsOffWhenNoRowExists(): void
    {
        $this->configRepository->method('getValue')->willReturn(null);

        self::assertFalse($this->config->isEnabled(42));
        self::assertFalse($this->config->isEnabled(null));
    }

    public function testGlobalRowOverridesBuiltInDefault(): void
    {
        $this->configRepository->method('getValue')
            ->willReturnCallback(static fn (int $ownerId): ?string => 0 === $ownerId ? '1' : null);

        self::assertTrue($this->config->isEnabled(42));
    }

    public function testPerUserRowBeatsGlobal(): void
    {
        // User 1 has an explicit ON row; the global row is OFF.
        $this->configRepository->method('getValue')
            ->willReturnCallback(static fn (int $ownerId): string => 0 === $ownerId ? '0' : '1');

        self::assertTrue($this->config->isEnabled(1));
    }

    public function testGlobalOffKeepsFeatureOffForUsersWithoutOverride(): void
    {
        $this->configRepository->method('getValue')
            ->willReturnCallback(static fn (int $ownerId): ?string => 0 === $ownerId ? '0' : null);

        self::assertFalse($this->config->isEnabled(7));
    }

    public function testAnonymousUserSkipsPerUserLookup(): void
    {
        $this->configRepository->expects(self::once())
            ->method('getValue')
            ->with(0, DesktopAgentConfig::CONFIG_GROUP, DesktopAgentConfig::KEY_ENABLED)
            ->willReturn('1');

        self::assertTrue($this->config->isEnabled(null));
    }

    public function testMalformedValueFallsBackToDefaultOff(): void
    {
        $this->configRepository->method('getValue')
            ->willReturnCallback(static fn (int $ownerId): ?string => 0 === $ownerId ? 'garbage' : null);

        self::assertFalse($this->config->isEnabled(1));
    }
}
