<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bundle;

use App\Bundle\BundleConfig;
use App\Repository\ConfigRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class BundleConfigTest extends TestCase
{
    private ConfigRepository&MockObject $configRepository;
    private BundleConfig $config;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->config = new BundleConfig($this->configRepository);
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
}
