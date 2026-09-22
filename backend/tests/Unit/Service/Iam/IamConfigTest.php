<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam;

use App\Entity\Share;
use App\Repository\ConfigRepository;
use App\Service\Iam\IamConfig;
use App\Service\RegistrationConfig;
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
        self::assertFalse($this->iam->isUserSearchEnabled(1));
        self::assertFalse($this->iam->isDirectorySyncEnabled(1));
        self::assertFalse($this->iam->isGroupPoliciesEnabled(1));
        self::assertFalse($this->iam->isImpersonationDisabled(1));
        self::assertSame(IamConfig::DEFAULT_DIRECTORY_GROUPS_CLAIM, $this->iam->directoryGroupsClaim(1));
        self::assertSame(IamConfig::DEFAULT_AUDIT_RETENTION_DAYS, $this->iam->auditRetentionDays(1));
        self::assertSame(IamConfig::IMPERSONATION_AUDITED, $this->iam->adminImpersonationPolicy(1));
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

    public function testMissingEveryonePolicyFailsClosedWhileSignUpIsOpen(): void
    {
        self::assertSame(
            IamConfig::EVERYONE_SHARES_DISABLED,
            $this->everyonePolicy(registration: null, everyone: null),
        );
        self::assertSame(
            IamConfig::EVERYONE_SHARES_DISABLED,
            $this->everyonePolicy(registration: '1', everyone: 'yes'),
        );
    }

    public function testMissingEveryonePolicyKeepsCompanyDefaultWhenSignUpIsClosed(): void
    {
        self::assertSame(
            IamConfig::EVERYONE_SHARES_ANY_OWNER,
            $this->everyonePolicy(registration: '0', everyone: null),
        );
    }

    public function testStoredEveryonePolicyIsHonoured(): void
    {
        self::assertSame(
            IamConfig::EVERYONE_SHARES_ANY_OWNER,
            $this->everyonePolicy(registration: '1', everyone: IamConfig::EVERYONE_SHARES_ANY_OWNER),
        );
    }

    public function testPerUserEveryoneRowNarrowsButNeverWidensTheGlobalPolicy(): void
    {
        $iam = $this->everyoneRows(
            global: IamConfig::EVERYONE_SHARES_ANY_OWNER,
            perUser: [4 => IamConfig::EVERYONE_SHARES_ADMINS_ONLY, 5 => 'garbage'],
        );
        self::assertSame(IamConfig::EVERYONE_SHARES_ADMINS_ONLY, $iam->everyoneSharesPolicy(4));
        // An unrecognized per-user value falls back to the global row, not to the sign-up rule.
        self::assertSame(IamConfig::EVERYONE_SHARES_ANY_OWNER, $iam->everyoneSharesPolicy(5));
        self::assertSame(IamConfig::EVERYONE_SHARES_ANY_OWNER, $iam->everyoneSharesPolicy(9));
        self::assertTrue($iam->isEveryoneAudienceEnabled());

        $closed = $this->everyoneRows(
            global: IamConfig::EVERYONE_SHARES_DISABLED,
            perUser: [4 => IamConfig::EVERYONE_SHARES_ANY_OWNER],
        );
        self::assertSame(IamConfig::EVERYONE_SHARES_DISABLED, $closed->everyoneSharesPolicy(4));
        self::assertFalse($closed->isEveryoneAudienceEnabled());
    }

    public function testOnlyPlatformEveryoneGrantsReachAccountsWhileTheAudienceIsOff(): void
    {
        $person = (new Share())->setSubjectType(Share::SUBJECT_EVERYONE)->setGrantedBy(7);
        $platform = (new Share())->setSubjectType(Share::SUBJECT_EVERYONE)->setGrantedBy(Share::PLATFORM_GRANTOR);
        $direct = (new Share())->setSubjectType(Share::SUBJECT_USER)->setSubjectId(3)->setGrantedBy(7);

        $off = $this->everyoneRows(global: IamConfig::EVERYONE_SHARES_DISABLED, perUser: []);
        self::assertFalse($off->everyoneShareReaches($person));
        self::assertTrue($off->everyoneShareReaches($platform));
        self::assertTrue($off->everyoneShareReaches($direct));

        $on = $this->everyoneRows(global: IamConfig::EVERYONE_SHARES_ADMINS_ONLY, perUser: []);
        self::assertTrue($on->everyoneShareReaches($person));
        self::assertTrue($on->everyoneShareReaches($platform));
    }

    /**
     * @param array<int, string> $perUser
     */
    private function everyoneRows(string $global, array $perUser): IamConfig
    {
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getValue')->willReturnCallback(
            static function (int $ownerId, string $group, string $setting) use ($global, $perUser): ?string {
                if (IamConfig::CONFIG_GROUP !== $group || IamConfig::KEY_EVERYONE_SHARES !== $setting) {
                    return null;
                }

                return 0 === $ownerId ? $global : ($perUser[$ownerId] ?? null);
            }
        );

        return new IamConfig($config);
    }

    private function everyonePolicy(?string $registration, ?string $everyone): string
    {
        $previous = $_ENV[RegistrationConfig::ENV_VAR] ?? null;
        unset($_ENV[RegistrationConfig::ENV_VAR]);
        try {
            $config = $this->createMock(ConfigRepository::class);
            $config->method('getValue')->willReturnCallback(
                static function (int $ownerId, string $group, string $setting) use ($registration, $everyone): ?string {
                    if (0 !== $ownerId) {
                        return null;
                    }
                    if (RegistrationConfig::CONFIG_GROUP === $group && RegistrationConfig::KEY_ENABLED === $setting) {
                        return $registration;
                    }
                    if (IamConfig::CONFIG_GROUP === $group && IamConfig::KEY_EVERYONE_SHARES === $setting) {
                        return $everyone;
                    }

                    return null;
                }
            );
            $iam = new IamConfig($config, null, new RegistrationConfig($config));

            return $iam->everyoneSharesPolicy(null);
        } finally {
            if (null === $previous) {
                unset($_ENV[RegistrationConfig::ENV_VAR]);
            } else {
                $_ENV[RegistrationConfig::ENV_VAR] = $previous;
            }
        }
    }
}
