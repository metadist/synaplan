<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam;

use App\Entity\AuditLogEntry;
use App\Entity\User;
use App\Repository\GroupRepository;
use App\Repository\UserRepository;
use App\Service\Iam\AuditRowLabels;
use App\Service\Iam\ResourceKind\ResourceCard;
use App\Service\Iam\ResourceKind\ResourceKindRegistry;
use App\Service\Iam\ResourceKind\ShareableResourceKindInterface;
use PHPUnit\Framework\TestCase;

final class AuditRowLabelsTest extends TestCase
{
    public function testTargetUserIdIsNamed(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $user->method('getDisplayName')->willReturn('Ada Lovelace');

        $users = $this->createStub(UserRepository::class);
        $users->method('findBy')->willReturn([$user]);

        $labels = new AuditRowLabels(
            $users,
            $this->createStub(GroupRepository::class),
            $this->createStub(ResourceKindRegistry::class),
        );

        $row = (new AuditLogEntry())
            ->setActorId(1)
            ->setAction('directory.sync')
            ->setResourceKind('group')
            ->setResourceId('4')
            ->setSubject(['change' => 'add', 'targetUserId' => 7]);

        $named = $labels->forRows([$row]);

        self::assertSame('Ada Lovelace', $named[0]['subjectName']);
    }

    public function testRepeatedChatsAreDescribedOnce(): void
    {
        $kind = $this->createMock(ShareableResourceKindInterface::class);
        $kind->expects(self::once())
            ->method('describeMany')
            ->with(['10', '11'])
            ->willReturn([
                '10' => new ResourceCard('10', 'Q3 plan', 'chat'),
                '11' => new ResourceCard('11', '#11', 'chat'),
            ]);
        $kind->expects(self::never())->method('describe');

        $registry = $this->createMock(ResourceKindRegistry::class);
        $registry->expects(self::atLeastOnce())->method('get')->with('conversation')->willReturn($kind);

        $labels = new AuditRowLabels(
            $this->createStub(UserRepository::class),
            $this->createStub(GroupRepository::class),
            $registry,
        );

        $first = (new AuditLogEntry())
            ->setActorId(1)
            ->setAction('share.grant')
            ->setResourceKind('conversation')
            ->setResourceId('10');
        $second = (new AuditLogEntry())
            ->setActorId(1)
            ->setAction('share.revoke')
            ->setResourceKind('conversation')
            ->setResourceId('10');
        $third = (new AuditLogEntry())
            ->setActorId(1)
            ->setAction('share.grant')
            ->setResourceKind('conversation')
            ->setResourceId('11');

        $named = $labels->forRows([$first, $second, $third]);

        self::assertSame('Q3 plan', $named[0]['resourceName']);
        self::assertSame('Q3 plan', $named[1]['resourceName']);
        self::assertNull($named[2]['resourceName']);
    }
}
