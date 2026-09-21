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
     * Issue #2060: with user search off (the default), a picker query must
     * not touch the user directory at all — no accounts, no mailboxes.
     * Groups stay searchable.
     */
    public function testUserSearchOffHidesAccountsButKeepsGroups(): void
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

    public function testUserSearchOnReturnsAccounts(): void
    {
        $account = $this->createStub(User::class);
        $account->method('getId')->willReturn(7);
        $account->method('getMail')->willReturn('sam@example.com');
        $account->method('getUserDetails')->willReturn(['first_name' => 'Sam']);
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
    ): ShareService {
        $iamConfig = $this->createMock(IamConfig::class);
        $iamConfig->method('isUserSearchEnabled')->willReturn($userSearchOn);
        $iamConfig->method('canShareWithEveryone')->willReturn($everyoneAllowed);

        return new ShareService(
            $this->createMock(ShareRepository::class),
            $groups,
            $this->createMock(GroupMemberRepository::class),
            $users,
            $this->createMock(ResourceKindRegistry::class),
            $this->createMock(AccessGate::class),
            $iamConfig,
            $this->createMock(AuditLogWriter::class),
        );
    }
}
