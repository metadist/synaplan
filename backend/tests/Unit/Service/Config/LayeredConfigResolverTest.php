<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Config;

use App\Entity\Config;
use App\Entity\GroupConfig;
use App\Entity\GroupMember;
use App\Repository\ConfigRepository;
use App\Repository\GroupConfigRepository;
use App\Repository\GroupMemberRepository;
use App\Service\Config\LayeredConfigResolver;
use App\Service\Iam\IamConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class LayeredConfigResolverTest extends TestCase
{
    private ConfigRepository&MockObject $config;
    private GroupConfigRepository&MockObject $groupConfig;
    private GroupMemberRepository&MockObject $members;
    private IamConfig&MockObject $iam;
    private LayeredConfigResolver $resolver;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigRepository::class);
        $this->groupConfig = $this->createMock(GroupConfigRepository::class);
        $this->members = $this->createMock(GroupMemberRepository::class);
        $this->iam = $this->createMock(IamConfig::class);
        $this->resolver = new LayeredConfigResolver(
            $this->config,
            $this->groupConfig,
            $this->members,
            $this->iam,
        );
    }

    public function testChainIsUserThenGlobalWhenOff(): void
    {
        $this->iam->method('isGroupPoliciesEnabled')->willReturn(false);
        $this->config->method('getValue')->willReturnCallback(
            static function (int $ownerId, string $group, string $setting): ?string {
                if ('DEFAULTMODEL' !== $group || 'CHAT' !== $setting) {
                    return null;
                }

                return 4 === $ownerId ? '10' : '20';
            }
        );
        $this->config->method('findByOwnerGroupAndSetting')->willReturn($this->configRow('20'));
        $this->groupConfig->expects(self::never())->method('getForGroups');

        self::assertSame(['10', '20'], $this->resolver->chain(4, 'DEFAULTMODEL', 'CHAT'));
        self::assertSame('10', $this->resolver->resolve(4, 'DEFAULTMODEL', 'CHAT'));
        self::assertSame('user', $this->resolver->source(4, 'DEFAULTMODEL', 'CHAT'));
    }

    public function testBlockedGlobalWinsOverUserAndGroup(): void
    {
        $this->iam->method('isGroupPoliciesEnabled')->willReturn(true);
        $blocked = $this->configRow('99', true);
        $this->config->method('getValue')->willReturn('10');
        $this->config->method('findByOwnerGroupAndSetting')->willReturn($blocked);
        $this->groupConfig->expects(self::never())->method('getForGroups');

        self::assertSame(['99'], $this->resolver->chain(4, 'DEFAULTMODEL', 'CHAT'));
        self::assertSame('admin', $this->resolver->source(4, 'DEFAULTMODEL', 'CHAT'));
        self::assertTrue($this->resolver->isLocked('DEFAULTMODEL', 'CHAT', 4));
    }

    public function testFirstByBidUnionOrAndHighest(): void
    {
        $this->iam->method('isGroupPoliciesEnabled')->willReturn(true);
        $this->config->method('getValue')->willReturn(null);
        $this->config->method('findByOwnerGroupAndSetting')->willReturn(null);
        $this->members->method('findByUserId')->willReturn([
            new GroupMember(2, 4),
            new GroupMember(9, 4),
        ]);
        $this->groupConfig->method('getForGroups')->willReturnCallback(
            function (array $ids, string $group, string $setting): array {
                if ('DEFAULTMODEL' === $group && 'CHAT' === $setting) {
                    return [
                        $this->groupRow(9, 'later-key'),
                        $this->groupRow(2, 'first-key'),
                    ];
                }
                if ('MODELS' === $group && 'ALLOWED' === $setting) {
                    return [
                        $this->groupRow(2, '["a"]'),
                        $this->groupRow(9, '["b","a"]'),
                    ];
                }
                if ('SAVEDTASKS' === $group && 'ENABLED' === $setting) {
                    return [
                        $this->groupRow(2, '0'),
                        $this->groupRow(9, '1'),
                    ];
                }
                if ('RATELIMITS' === $group && 'TIER' === $setting) {
                    return [
                        $this->groupRow(2, 'PRO'),
                        $this->groupRow(9, 'TEAM'),
                    ];
                }

                return [];
            }
        );

        self::assertSame('first-key', $this->resolver->resolve(4, 'DEFAULTMODEL', 'CHAT'));
        self::assertSame('["a","b"]', $this->resolver->resolve(4, 'MODELS', 'ALLOWED'));
        self::assertTrue($this->resolver->resolveBool(4, 'SAVEDTASKS', 'ENABLED', false));
        self::assertSame('TEAM', $this->resolver->resolve(4, 'RATELIMITS', 'TIER'));
    }

    public function testKeysOutsideAllowListSkipGroups(): void
    {
        $this->iam->method('isGroupPoliciesEnabled')->willReturn(true);
        $this->config->method('getValue')->willReturnCallback(
            static function (int $ownerId, string $group, string $setting): ?string {
                if ('IAM' === $group && 'GROUPS_ENABLED' === $setting) {
                    return 0 === $ownerId ? '1' : null;
                }

                return null;
            }
        );
        $this->config->method('findByOwnerGroupAndSetting')->willReturn($this->configRow('1'));
        $this->groupConfig->expects(self::never())->method('getForGroups');

        self::assertSame(['1'], $this->resolver->chain(4, 'IAM', 'GROUPS_ENABLED'));
    }

    private function configRow(string $value, bool $blocked = false): Config
    {
        $row = new Config();
        $row->setOwnerId(0);
        $row->setGroup('DEFAULTMODEL');
        $row->setSetting('CHAT');
        $row->setValue($value);
        $row->setBlocked($blocked);

        return $row;
    }

    private function groupRow(int $groupId, string $value): GroupConfig
    {
        $row = new GroupConfig();
        $row->setGroupId($groupId);
        $row->setGroup('x');
        $row->setSetting('y');
        $row->setValue($value);

        return $row;
    }
}
