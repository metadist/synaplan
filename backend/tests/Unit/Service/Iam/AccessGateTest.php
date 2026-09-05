<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam;

use App\Entity\User;
use App\Service\Iam\AccessGate;
use App\Service\Iam\IamConfig;
use App\Service\Iam\Permission;
use App\Service\Iam\ResourceKind\ResourceKindRegistry;
use App\Service\Iam\ResourceKind\ShareableResourceKindInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class AccessGateTest extends TestCase
{
    private IamConfig&MockObject $iamConfig;
    private ShareableResourceKindInterface&MockObject $kind;
    private AccessGate $gate;

    protected function setUp(): void
    {
        $this->iamConfig = $this->createMock(IamConfig::class);
        $this->kind = $this->createMock(ShareableResourceKindInterface::class);
        $this->kind->method('key')->willReturn('conversation');
        $registry = new ResourceKindRegistry([$this->kind]);
        $this->gate = new AccessGate($this->iamConfig, $registry, new RequestStack());
    }

    public function testFlagOffNeverQueriesIamTables(): void
    {
        $this->iamConfig->expects(self::once())->method('isGroupsEnabled')->willReturn(false);
        $this->kind->expects(self::once())->method('ownerId')->with('42')->willReturn(7);

        $user = $this->userWithId(7);
        self::assertTrue($this->gate->decide($user, 'conversation', '42', Permission::Read));
    }

    public function testAdminIsNotOwner(): void
    {
        $this->iamConfig->method('isGroupsEnabled')->willReturn(true);
        $this->kind->method('ownerId')->willReturn(2);

        $admin = $this->userWithId(1);
        $admin->setUserLevel('ADMIN');

        self::assertFalse($this->gate->decide($admin, 'conversation', '99', Permission::Read));
        self::assertFalse($this->gate->decide($admin, 'conversation', '99', Permission::Use));
    }

    public function testOwnerIsGrantedEveryLevel(): void
    {
        $this->iamConfig->method('isGroupsEnabled')->willReturn(true);
        $this->kind->method('ownerId')->willReturn(5);

        $owner = $this->userWithId(5);
        self::assertTrue($this->gate->decide($owner, 'conversation', '1', Permission::Read));
        self::assertTrue($this->gate->decide($owner, 'conversation', '1', Permission::Manage));
    }

    public function testUnknownKindIsDenied(): void
    {
        $this->iamConfig->method('isGroupsEnabled')->willReturn(false);

        self::assertFalse($this->gate->decide($this->userWithId(1), 'widget', '1', Permission::Read));
    }

    public function testPerRequestMemoReusesOwnerLookup(): void
    {
        $stack = new RequestStack();
        $stack->push(new Request());
        $this->kind->expects(self::once())->method('ownerId')->willReturn(3);
        $registry = new ResourceKindRegistry([$this->kind]);
        $this->iamConfig->method('isGroupsEnabled')->willReturn(true);

        $gate = new AccessGate($this->iamConfig, $registry, $stack);
        $user = $this->userWithId(3);

        self::assertTrue($gate->decide($user, 'conversation', '8', Permission::Read));
        self::assertTrue($gate->decide($user, 'conversation', '8', Permission::Use));
    }

    private function userWithId(int $id): User
    {
        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        return $user;
    }
}
