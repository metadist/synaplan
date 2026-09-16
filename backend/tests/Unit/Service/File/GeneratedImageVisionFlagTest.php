<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Repository\ConfigRepository;
use App\Service\File\GeneratedImageVisionFlag;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GeneratedImageVisionFlagTest extends TestCase
{
    private ConfigRepository&MockObject $configRepository;
    private GeneratedImageVisionFlag $flag;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->flag = new GeneratedImageVisionFlag($this->configRepository);
    }

    public function testDefaultsOnWhenNoRowsExist(): void
    {
        $this->configRepository->method('getValue')->willReturn(null);

        self::assertTrue($this->flag->isEnabled(42));
        self::assertTrue($this->flag->isEnabled(null));
    }

    public function testGlobalOffIsTheKillSwitch(): void
    {
        $this->configRepository->method('getValue')
            ->willReturnCallback(static function (int $ownerId): ?string {
                return 0 === $ownerId ? '0' : null;
            });

        self::assertFalse($this->flag->isEnabled(42));
        self::assertFalse($this->flag->isEnabled(null));
    }

    public function testPerUserOffBeatsGlobalOn(): void
    {
        $this->configRepository->method('getValue')
            ->willReturnCallback(static function (int $ownerId): string {
                return 0 === $ownerId ? '1' : '0';
            });

        self::assertFalse($this->flag->isEnabled(7));
    }
}
