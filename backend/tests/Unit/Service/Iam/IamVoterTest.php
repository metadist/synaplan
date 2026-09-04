<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam;

use App\Entity\User;
use App\Security\Voter\IamVoter;
use App\Service\Iam\AccessGate;
use App\Service\Iam\Permission;
use App\Service\Iam\ResourceRef;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class IamVoterTest extends TestCase
{
    public function testOwnerReadIsGranted(): void
    {
        $user = $this->userWithId(4);
        $gate = $this->createMock(AccessGate::class);
        $gate->expects(self::once())
            ->method('decide')
            ->with($user, 'conversation', '12', Permission::Read)
            ->willReturn(true);

        $voter = new IamVoter($gate);
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        self::assertTrue($voter->vote($token, new ResourceRef('conversation', '12'), [IamVoter::READ]) > 0);
    }

    public function testAnonymousIsDenied(): void
    {
        $gate = $this->createMock(AccessGate::class);
        $gate->expects(self::never())->method('decide');
        $voter = new IamVoter($gate);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        self::assertTrue($voter->vote($token, new ResourceRef('conversation', '12'), [IamVoter::READ]) < 0);
    }

    private function userWithId(int $id): User
    {
        $user = new User();
        $user->setMail('voter@example.com');
        $user->setUserLevel('NEW');
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        return $user;
    }
}
