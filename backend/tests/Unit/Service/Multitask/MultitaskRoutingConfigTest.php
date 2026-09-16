<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask;

use App\Entity\Config;
use App\Entity\GroupConfig;
use App\Entity\GroupMember;
use App\Repository\ConfigRepository;
use App\Repository\GroupConfigRepository;
use App\Repository\GroupMemberRepository;
use App\Service\Config\LayeredConfigResolver;
use App\Service\Iam\IamConfig;
use App\Service\Multitask\MultitaskRoutingConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MultitaskRoutingConfigTest extends TestCase
{
    private ConfigRepository&MockObject $configRepository;
    private MultitaskRoutingConfig $config;

    protected function setUp(): void
    {
        $this->configRepository = $this->createMock(ConfigRepository::class);
        $this->config = new MultitaskRoutingConfig($this->configRepository);
    }

    public function testRoutingDefaultsOnWhenNoRowsExist(): void
    {
        // No user row, no global row → built-in default ON.
        $this->configRepository->method('getValue')->willReturn(null);

        self::assertTrue($this->config->isRoutingEnabled(42));
        self::assertTrue($this->config->isRoutingEnabled(null));
    }

    public function testGlobalRowOverridesBuiltInDefault(): void
    {
        // Global row says off; no user row.
        $this->configRepository->method('getValue')
            ->willReturnCallback(static function (int $ownerId): ?string {
                return 0 === $ownerId ? '0' : null;
            });

        self::assertFalse($this->config->isRoutingEnabled(42));
    }

    public function testPerUserRowOverridesGlobal(): void
    {
        // Global ON, but this (grandfathered) user is explicitly OFF.
        $this->configRepository->method('getValue')
            ->willReturnCallback(static function (int $ownerId): string {
                return 0 === $ownerId ? '1' : '0';
            });

        self::assertFalse($this->config->isRoutingEnabled(7));
    }

    public function testPerUserOnBeatsGlobalOff(): void
    {
        $this->configRepository->method('getValue')
            ->willReturnCallback(static function (int $ownerId): string {
                return 0 === $ownerId ? '0' : '1';
            });

        self::assertTrue($this->config->isRoutingEnabled(7));
    }

    public function testAnonymousUserIgnoresPerUserLookup(): void
    {
        // userId null/0 must never trigger a per-user lookup; only global is read.
        $this->configRepository->expects(self::once())
            ->method('getValue')
            ->with(0, MultitaskRoutingConfig::CONFIG_GROUP, MultitaskRoutingConfig::KEY_ROUTING_ENABLED)
            ->willReturn('1');

        self::assertTrue($this->config->isRoutingEnabled(null));
    }

    public function testShadowModeDefaultsOff(): void
    {
        $this->configRepository->method('getValue')->willReturn(null);

        self::assertFalse($this->config->isShadowMode());
    }

    public function testParallelDefaultsOff(): void
    {
        $this->configRepository->method('getValue')->willReturn(null);

        self::assertFalse($this->config->isParallelEnabled(7));
        self::assertFalse($this->config->isParallelEnabled(null));
    }

    public function testParallelEnabledForwardsTheUserIdToTheLayeredResolver(): void
    {
        $layered = $this->createMock(LayeredConfigResolver::class);
        $layered->expects(self::once())
            ->method('resolveBool')
            ->with(9, MultitaskRoutingConfig::CONFIG_GROUP, MultitaskRoutingConfig::KEY_PARALLEL_ENABLED, false)
            ->willReturn(true);

        $config = new MultitaskRoutingConfig($this->configRepository, $layered);

        self::assertTrue($config->isParallelEnabled(9));
    }

    public function testParallelEnabledWithoutAUserSkipsTheGroupLayer(): void
    {
        $layered = $this->createMock(LayeredConfigResolver::class);
        $layered->expects(self::once())
            ->method('resolveBool')
            ->with(null, MultitaskRoutingConfig::CONFIG_GROUP, MultitaskRoutingConfig::KEY_PARALLEL_ENABLED, false)
            ->willReturn(false);

        $config = new MultitaskRoutingConfig($this->configRepository, $layered);

        self::assertFalse($config->isParallelEnabled(null));
    }

    /**
     * Issue #1873: MULTITASK.PARALLEL_ENABLED is on the group-policy allow-list.
     * A mocked resolveBool() would still pass if the flag never consulted
     * BGROUPCONFIG. Wire a real LayeredConfigResolver so a member's group
     * value 1 wins over the global 0, and a null user stays on the global row.
     */
    public function testParallelEnabledHonoursGroupPolicyOverGlobalOff(): void
    {
        $configRepository = $this->createMock(ConfigRepository::class);
        $groupConfig = $this->createMock(GroupConfigRepository::class);
        $members = $this->createMock(GroupMemberRepository::class);
        $iam = $this->createMock(IamConfig::class);

        $iam->method('isGroupPoliciesEnabled')->willReturn(true);
        $configRepository->method('getValue')->willReturn(null);
        $global = new Config();
        $global->setOwnerId(0);
        $global->setGroup(MultitaskRoutingConfig::CONFIG_GROUP);
        $global->setSetting(MultitaskRoutingConfig::KEY_PARALLEL_ENABLED);
        $global->setValue('0');
        $configRepository->expects(self::atLeastOnce())
            ->method('findByOwnerGroupAndSetting')
            ->with(0, MultitaskRoutingConfig::CONFIG_GROUP, MultitaskRoutingConfig::KEY_PARALLEL_ENABLED)
            ->willReturn($global);

        $members->expects(self::once())
            ->method('findByUserId')
            ->with(9)
            ->willReturn([new GroupMember(4, 9)]);
        $groupRow = new GroupConfig();
        $groupRow->setGroupId(4);
        $groupRow->setGroup(MultitaskRoutingConfig::CONFIG_GROUP);
        $groupRow->setSetting(MultitaskRoutingConfig::KEY_PARALLEL_ENABLED);
        $groupRow->setValue('1');
        $groupConfig->expects(self::once())
            ->method('getForGroups')
            ->with([4], MultitaskRoutingConfig::CONFIG_GROUP, MultitaskRoutingConfig::KEY_PARALLEL_ENABLED)
            ->willReturn([$groupRow]);

        $config = new MultitaskRoutingConfig(
            $configRepository,
            new LayeredConfigResolver($configRepository, $groupConfig, $members, $iam),
        );

        self::assertTrue($config->isParallelEnabled(9));
        self::assertFalse($config->isParallelEnabled(null));
    }

    public function testShadowModeReadsGlobalOnly(): void
    {
        $this->configRepository->expects(self::once())
            ->method('getValue')
            ->with(0, MultitaskRoutingConfig::CONFIG_GROUP, MultitaskRoutingConfig::KEY_SHADOW_MODE)
            ->willReturn('true');

        self::assertTrue($this->config->isShadowMode());
    }

    public function testMalformedValueFallsBackToDefault(): void
    {
        // A non-boolean global value must fall back to the built-in default (ON for routing).
        $this->configRepository->method('getValue')
            ->willReturnCallback(static function (int $ownerId): ?string {
                return 0 === $ownerId ? 'not-a-bool' : null;
            });

        self::assertTrue($this->config->isRoutingEnabled(1));
    }
}
