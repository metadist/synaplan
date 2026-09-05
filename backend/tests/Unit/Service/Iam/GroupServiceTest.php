<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam;

use App\Entity\AuditLogEntry;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\AuditLogEntryRepository;
use App\Repository\GroupMemberRepository;
use App\Repository\GroupRepository;
use App\Repository\ShareRepository;
use App\Repository\UserRepository;
use App\Service\Iam\AuditLogWriter;
use App\Service\Iam\Exception\DirectoryGroupReadOnlyException;
use App\Service\Iam\GroupService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GroupServiceTest extends TestCase
{
    private GroupRepository&MockObject $groups;
    private GroupMemberRepository&MockObject $members;
    private UserRepository&MockObject $users;
    private AuditLogEntryRepository&MockObject $audit;
    private GroupService $service;
    private User $actor;

    protected function setUp(): void
    {
        $this->groups = $this->createMock(GroupRepository::class);
        $this->members = $this->createMock(GroupMemberRepository::class);
        $this->users = $this->createMock(UserRepository::class);
        $this->audit = $this->createMock(AuditLogEntryRepository::class);
        $this->service = new GroupService(
            $this->groups,
            $this->members,
            $this->users,
            new AuditLogWriter($this->audit),
            $this->createMock(ShareRepository::class),
        );
        $this->actor = $this->userWithId(1);
    }

    public function testCreateAssignsUniqueSlug(): void
    {
        $this->groups->expects(self::exactly(2))
            ->method('findOneBySlug')
            ->willReturnCallback(static function (string $slug): ?Group {
                return 'sales' === $slug ? new Group() : null;
            });
        $this->groups->expects(self::once())->method('save');
        $this->audit->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (AuditLogEntry $entry): bool {
                return 'group.create' === $entry->getAction();
            }));

        $group = $this->service->create('Sales', '', $this->actor);

        self::assertSame('Sales', $group->getName());
        self::assertSame('sales-2', $group->getSlug());
        self::assertSame(Group::KIND_MANUAL, $group->getKind());
    }

    public function testDeleteDirectoryGroupThrows409Shape(): void
    {
        $group = new Group();
        $group->setKind(Group::KIND_DIRECTORY);
        $ref = new \ReflectionProperty(Group::class, 'id');
        $ref->setValue($group, 9);

        $this->expectException(DirectoryGroupReadOnlyException::class);
        $this->members->expects(self::never())->method('deleteByGroupId');
        $this->audit->expects(self::never())->method('save');

        $this->service->delete($group, $this->actor);
    }

    public function testSetMemberWritesOneAuditRow(): void
    {
        $group = new Group();
        $ref = new \ReflectionProperty(Group::class, 'id');
        $ref->setValue($group, 3);
        $target = $this->userWithId(8);
        $this->users->expects(self::once())->method('find')->with(8)->willReturn($target);
        $this->members->method('findMembership')->willReturn(null);
        $this->members->expects(self::once())->method('save');
        $this->audit->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (AuditLogEntry $entry): bool {
                return 'group.member_set' === $entry->getAction()
                    && ['userId' => 8, 'role' => 'manager'] === $entry->getSubject();
            }));

        $member = $this->service->setMember($group, 8, 'manager', $this->actor);

        self::assertSame(8, $member->getUserId());
        self::assertSame('manager', $member->getRole());
    }

    public function testSetMemberOnDirectoryGroupIsRejected(): void
    {
        $group = $this->directoryGroup(9);
        $this->expectException(DirectoryGroupReadOnlyException::class);
        $this->users->expects(self::never())->method('find');
        $this->members->expects(self::never())->method('save');
        $this->audit->expects(self::never())->method('save');

        $this->service->setMember($group, 8, 'member', $this->actor);
    }

    public function testRemoveMemberOnDirectoryGroupIsRejected(): void
    {
        $group = $this->directoryGroup(9);
        $this->expectException(DirectoryGroupReadOnlyException::class);
        $this->members->expects(self::never())->method('findMembership');
        $this->members->expects(self::never())->method('remove');
        $this->audit->expects(self::never())->method('save');

        $this->service->removeMember($group, 8, $this->actor);
    }

    private function userWithId(int $id): User
    {
        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        return $user;
    }

    private function directoryGroup(int $id): Group
    {
        $group = new Group();
        $group->setKind(Group::KIND_DIRECTORY);
        $ref = new \ReflectionProperty(Group::class, 'id');
        $ref->setValue($group, $id);

        return $group;
    }
}
