<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam;

use App\Entity\Group;
use App\Entity\Share;
use App\Entity\User;
use App\Repository\GroupMemberRepository;
use App\Repository\GroupRepository;
use App\Repository\ShareRepository;
use App\Repository\UserRepository;
use App\Service\Iam\AccessGate;
use App\Service\Iam\AuditLogWriter;
use App\Service\Iam\IamConfig;
use App\Service\Iam\ResourceKind\ResourceKindRegistry;
use App\Service\Iam\ShareService;
use PHPUnit\Framework\TestCase;

final class ShareServiceSearchSubjectsTest extends TestCase
{
    /**
     * Issue #2106: with user search off and no shared group, a query returns
     * groups only. The directory search is not run.
     */
    public function testUserSearchOffWithoutASharedGroupReturnsGroupsOnly(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::never())->method('searchByEmailOrName');

        $group = $this->createStub(Group::class);
        $group->method('getId')->willReturn(11);
        $group->method('getName')->willReturn('Sales');
        $groups = $this->createMock(GroupRepository::class);
        $groups->method('searchByName')->willReturn([$group]);

        $service = $this->service($users, $groups, userSearchOn: false, everyoneAllowed: false);

        $subjects = $service->searchSubjects($this->actor(), 'sa');

        self::assertSame([
            ['type' => Share::SUBJECT_GROUP, 'id' => 11, 'name' => 'Sales', 'email' => null, 'pinned' => false],
        ], $subjects);
    }

    public function testUserSearchOffReturnsPeopleWhoShareAGroup(): void
    {
        $account = $this->createStub(User::class);
        $account->method('getId')->willReturn(7);
        $account->method('getMail')->willReturn('sam@example.com');
        $account->method('getDisplayName')->willReturn('Sam');
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::once())
            ->method('searchByEmailOrName')
            ->with('sa', 20, [7])
            ->willReturn([$account]);

        $groups = $this->createMock(GroupRepository::class);
        $groups->method('searchByName')->willReturn([]);

        $members = $this->createMock(GroupMemberRepository::class);
        $members->method('findCoMemberUserIds')->with(3)->willReturn([7]);

        $service = $this->service($users, $groups, userSearchOn: false, everyoneAllowed: false, members: $members);

        $subjects = $service->searchSubjects($this->actor(), 'sa');

        self::assertSame([
            ['type' => Share::SUBJECT_USER, 'id' => 7, 'name' => 'Sam', 'email' => 'sam@example.com', 'pinned' => false],
        ], $subjects);
    }

    public function testUserSearchOnReturnsAccounts(): void
    {
        $account = $this->createStub(User::class);
        $account->method('getId')->willReturn(7);
        $account->method('getMail')->willReturn('sam@example.com');
        $account->method('getDisplayName')->willReturn('Sam');
        $users = $this->createMock(UserRepository::class);
        $users->method('searchByEmailOrName')->willReturn([$account]);

        $groups = $this->createMock(GroupRepository::class);
        $groups->method('searchByName')->willReturn([]);

        $service = $this->service($users, $groups, userSearchOn: true, everyoneAllowed: false);

        $subjects = $service->searchSubjects($this->actor(), 'sa');

        self::assertSame([
            ['type' => Share::SUBJECT_USER, 'id' => 7, 'name' => 'Sam', 'email' => 'sam@example.com', 'pinned' => false],
        ], $subjects);
    }

    public function testEveryonePinIsIndependentOfUserSearch(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::never())->method('searchByEmailOrName');
        $groups = $this->createMock(GroupRepository::class);
        $groups->method('searchByName')->willReturn([]);

        $service = $this->service($users, $groups, userSearchOn: false, everyoneAllowed: true);

        $subjects = $service->searchSubjects($this->actor(), 'sa');

        self::assertSame([
            ['type' => Share::SUBJECT_EVERYONE, 'id' => 0, 'name' => '', 'email' => null, 'pinned' => true],
        ], $subjects);
    }

    private function actor(): User
    {
        $actor = $this->createStub(User::class);
        $actor->method('getId')->willReturn(3);

        return $actor;
    }

    private function service(
        UserRepository $users,
        GroupRepository $groups,
        bool $userSearchOn,
        bool $everyoneAllowed,
        ?GroupMemberRepository $members = null,
    ): ShareService {
        $iamConfig = $this->createMock(IamConfig::class);
        $iamConfig->method('isUserSearchEnabled')->willReturn($userSearchOn);
        $iamConfig->method('canShareWithEveryone')->willReturn($everyoneAllowed);

        return new ShareService(
            $this->createMock(ShareRepository::class),
            $groups,
            $members ?? $this->createMock(GroupMemberRepository::class),
            $users,
            $this->createMock(ResourceKindRegistry::class),
            $this->createMock(AccessGate::class),
            $iamConfig,
            $this->createMock(AuditLogWriter::class),
        );
    }
}
