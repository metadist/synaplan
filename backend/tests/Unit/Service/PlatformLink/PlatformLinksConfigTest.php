<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\PlatformLink;

use App\Repository\ConfigRepository;
use App\Service\PlatformLink\PlatformLinksConfig;
use PHPUnit\Framework\TestCase;

final class PlatformLinksConfigTest extends TestCase
{
    public function testDefaultsOffWhenNoRow(): void
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturn(null);
        $config = new PlatformLinksConfig($repo);

        self::assertFalse($config->isEnabled(null));
        self::assertFalse($config->isEnabled(4));
    }

    public function testPerUserOverrideWins(): void
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static fn (int $ownerId, string $group, string $setting): ?string => match (true) {
                4 === $ownerId && PlatformLinksConfig::CONFIG_GROUP === $group && PlatformLinksConfig::KEY_ENABLED === $setting => '1',
                0 === $ownerId => '0',
                default => null,
            }
        );
        $config = new PlatformLinksConfig($repo);

        self::assertTrue($config->isEnabled(4));
        self::assertFalse($config->isEnabled(9));
    }
}
