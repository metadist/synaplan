<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam;

use App\Entity\Group;
use App\Entity\GroupMember;
use App\Entity\User;
use App\Repository\GroupMemberRepository;
use App\Repository\GroupRepository;
use App\Service\Auth\OidcClaimResolver;
use App\Service\Iam\AuditLogWriter;
use App\Service\Iam\DirectoryGroupSync;
use App\Service\Iam\IamConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DirectoryGroupSyncTest extends TestCase
{
    private IamConfig&MockObject $iamConfig;
    private GroupRepository&MockObject $groups;
    private GroupMemberRepository&MockObject $members;
    private AuditLogWriter&MockObject $audit;
    private DirectoryGroupSync $sync;

    protected function setUp(): void
    {
        $this->iamConfig = $this->createMock(IamConfig::class);
        $this->groups = $this->createMock(GroupRepository::class);
        $this->members = $this->createMock(GroupMemberRepository::class);
        $this->audit = $this->createMock(AuditLogWriter::class);
        $this->sync = new DirectoryGroupSync(
            $this->iamConfig,
            new OidcClaimResolver(),
            $this->groups,
            $this->members,
            $this->audit,
            'synaplan',
            'https://idp.example/realms/synaplan/.well-known/openid-configuration',
        );
    }

    public function testNoopWhenSyncOff(): void
    {
        $this->iamConfig->method('isDirectorySyncEnabled')->willReturn(false);
        $this->groups->expects(self::never())->method('findOneByExternal');
        $this->groups->expects(self::never())->method('save');
        $this->members->expects(self::never())->method('save');
        $this->audit->expects(self::never())->method('record');

        $this->sync->sync($this->userWithId(4), [
            'sub' => 's1',
            'iss' => 'https://idp.example/realms/synaplan',
            'groups' => ['sales'],
        ]);
        self::assertFalse($this->sync->shouldRun($this->userWithId(4), 'refresh', 0));
    }

    public function testShouldRunAlwaysOnBrowserLogin(): void
    {
        $this->iamConfig->method('isDirectorySyncEnabled')->willReturn(true);

        self::assertTrue($this->sync->shouldRun($this->userWithId(4), 'refresh-token', time()));
    }

    public function testBearerThrottleSkipsRecentLastSeen(): void
    {
        $this->iamConfig->method('isDirectorySyncEnabled')->willReturn(true);

        self::assertFalse($this->sync->shouldRun($this->userWithId(4), null, time() - 10));
        self::assertTrue($this->sync->shouldRun($this->userWithId(4), null, time() - 400));
    }

    public function testUpsertBySourceAndExternalIdAndLeavesManualRows(): void
    {
        $this->iamConfig->method('isDirectorySyncEnabled')->willReturn(true);
        $this->iamConfig->method('directoryGroupsClaim')->willReturn('groups');
        $this->iamConfig->method('directoryGroupNames')->willReturn(['sales' => 'Sales']);

        $group = new Group();
        $group->setName('Sales');
        $ref = new \ReflectionProperty(Group::class, 'id');
        $ref->setValue($group, 11);

        $this->groups->expects(self::once())
            ->method('findOneByExternal')
            ->with('oidc:https://idp.example/realms/synaplan', 'sales')
            ->willReturn($group);

        $manual = new GroupMember(99, 4);
        $manual->setSource(GroupMember::SOURCE_MANUAL);
        $staleDirectory = new GroupMember(12, 4);
        $staleDirectory->setSource(GroupMember::SOURCE_DIRECTORY);

        $this->members->method('findDirectoryByUserId')->willReturn([$staleDirectory]);
        $this->members->method('findMembership')->willReturn($manual);
        $this->members->expects(self::once())->method('remove')->with($staleDirectory);
        $this->members->expects(self::never())->method('save');
        $this->audit->expects(self::once())
            ->method('record')
            ->with(4, 'directory.sync', 'group', '12', self::callback(static function (array $subject): bool {
                return 'remove' === ($subject['change'] ?? null);
            }), '');

        $this->sync->sync($this->userWithId(4), [
            'sub' => 's1',
            'iss' => 'https://idp.example/realms/synaplan',
            'groups' => ['sales'],
        ]);
    }

    public function testRolesUnchangedWhenSyncOn(): void
    {
        $this->iamConfig->method('isDirectorySyncEnabled')->willReturn(true);
        $this->iamConfig->method('directoryGroupsClaim')->willReturn('groups');
        $this->iamConfig->method('directoryGroupNames')->willReturn([]);
        $this->groups->method('findOneByExternal')->willReturn(null);
        $this->groups->method('findOneBySlug')->willReturn(null);
        $this->groups->method('save')->willReturnCallback(static function (Group $group): void {
            $ref = new \ReflectionProperty(Group::class, 'id');
            $ref->setValue($group, 21);
        });
        $this->members->method('findDirectoryByUserId')->willReturn([]);
        $this->members->method('findMembership')->willReturn(null);
        $this->members->expects(self::once())->method('save');

        $user = $this->userWithId(4);
        $user->setUserLevel('ADMIN');
        $this->sync->sync($user, [
            'sub' => 's1',
            'iss' => 'https://idp.example/realms/synaplan',
            'groups' => ['sales'],
            'realm_access' => ['roles' => ['admin']],
        ]);

        self::assertSame('ADMIN', $user->getUserLevel());
    }

    private function userWithId(int $id): User
    {
        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        return $user;
    }
}
