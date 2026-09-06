<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam;

use App\Repository\ConfigRepository;
use App\Service\Iam\IamConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class IamConfigTest extends TestCase
{
    private ConfigRepository&MockObject $config;
    private IamConfig $iam;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigRepository::class);
        $this->iam = new IamConfig($this->config);
    }

    public function testDefaultsOffWhenNoRowExists(): void
    {
        $this->config->method('getValue')->willReturn(null);

        self::assertFalse($this->iam->isGroupsEnabled(1));
        self::assertFalse($this->iam->isSharingEnabled(1));
        self::assertFalse($this->iam->isDirectorySyncEnabled(1));
    }

    public function testPerUserRowOverridesGlobal(): void
    {
        $this->config->method('getValue')->willReturnCallback(
            static function (int $ownerId, string $group, string $setting): ?string {
                if (IamConfig::CONFIG_GROUP !== $group || IamConfig::KEY_GROUPS_ENABLED !== $setting) {
                    return null;
                }

                return 4 === $ownerId ? '1' : '0';
            }
        );

        self::assertTrue($this->iam->isGroupsEnabled(4));
        self::assertFalse($this->iam->isGroupsEnabled(9));
    }

    public function testSharingRequiresGroups(): void
    {
        $this->config->method('getValue')->willReturnCallback(
            static function (int $ownerId, string $group, string $setting): ?string {
                if (IamConfig::CONFIG_GROUP !== $group || 0 !== $ownerId) {
                    return null;
                }

                return match ($setting) {
                    IamConfig::KEY_GROUPS_ENABLED => '0',
                    IamConfig::KEY_SHARING_ENABLED => '1',
                    default => null,
                };
            }
        );

        self::assertFalse($this->iam->isSharingEnabled(1));
    }
}
