<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ExternalIdentity;
use App\Entity\User;
use App\Repository\ExternalIdentityRepository;
use App\Repository\UserRepository;
use App\Service\ModelConfigService;
use App\Service\OidcUserService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OidcUserServiceExternalIdentityTest extends TestCase
{
    private UserRepository&MockObject $userRepository;
    private EntityManagerInterface&MockObject $em;
    private ExternalIdentityRepository&MockObject $externalIdentities;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->externalIdentities = $this->createMock(ExternalIdentityRepository::class);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn(null);
        $qb->method('getQuery')->willReturn($query);
        $this->userRepository->method('createQueryBuilder')->willReturn($qb);
    }

    public function testUpsertsOidcIdentityFromIssuerAndBumpsLastSeen(): void
    {
        $user = $this->keycloakUser(11, 'oidc-ident@synaplan.internal');
        $this->userRepository->method('findOneBy')->willReturn($user);
        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())->method('flush');

        $this->externalIdentities->method('findOneOidcBySub')->willReturn(null);
        $this->externalIdentities->expects(self::once())
            ->method('upsert')
            ->with(11, 'oidc:https://idp.example/realms/synaplan', 'sub-abc')
            ->willReturn($this->createStub(ExternalIdentity::class));

        $this->service()->findOrCreateFromClaims([
            'sub' => 'sub-abc',
            'email' => 'oidc-ident@synaplan.internal',
            'iss' => 'https://idp.example/realms/synaplan',
            'realm_access' => ['roles' => ['default-roles-synaplan']],
        ]);

        self::assertSame('NEW', $user->getUserLevel());
    }

    public function testFindsUserFromIdentityTableBeforeJsonFallback(): void
    {
        $user = $this->keycloakUser(22, 'table-first@synaplan.internal');
        $identity = new ExternalIdentity();
        $identity->setUserId(22);
        $identity->setSource('oidc:https://idp.example');
        $identity->setExternalId('sub-table');

        $this->externalIdentities->expects(self::once())
            ->method('findOneOidcBySub')
            ->with('sub-table')
            ->willReturn($identity);
        $this->userRepository->expects(self::once())->method('find')->with(22)->willReturn($user);
        $this->userRepository->expects(self::never())->method('findOneBy');
        $this->em->expects(self::once())->method('persist');
        $this->em->expects(self::once())->method('flush');
        $this->externalIdentities->expects(self::once())
            ->method('upsert')
            ->with(22, 'oidc:https://idp.example', 'sub-table')
            ->willReturn($identity);

        $result = $this->service()->findOrCreateFromClaims([
            'sub' => 'sub-table',
            'email' => 'table-first@synaplan.internal',
            'iss' => 'https://idp.example',
        ]);

        self::assertSame($user, $result);
        self::assertSame('NEW', $result->getUserLevel());
    }

    public function testFallsBackToDiscoveryIssuerWhenIssMissing(): void
    {
        $user = $this->keycloakUser(33, 'no-iss@synaplan.internal');
        $this->userRepository->method('findOneBy')->willReturn($user);
        $this->em->method('persist');
        $this->em->method('flush');
        $this->externalIdentities->method('findOneOidcBySub')->willReturn(null);
        $this->externalIdentities->expects(self::once())
            ->method('upsert')
            ->with(33, 'oidc:https://idp.example/realms/synaplan', 'sub-no-iss')
            ->willReturn($this->createStub(ExternalIdentity::class));

        $this->service()->findOrCreateFromClaims([
            'sub' => 'sub-no-iss',
            'email' => 'no-iss@synaplan.internal',
        ]);
    }

    private function service(): OidcUserService
    {
        return new OidcUserService(
            $this->userRepository,
            $this->em,
            $this->createStub(ModelConfigService::class),
            new NullLogger(),
            $this->externalIdentities,
            'admin',
            'realm_access.roles',
            'test-client-id',
            'https://idp.example/realms/synaplan/.well-known/openid-configuration',
        );
    }

    private function keycloakUser(int $id, string $email): User
    {
        $user = new User();
        $user->setMail($email);
        $user->setProviderId('keycloak');
        $user->setUserLevel('NEW');
        $user->setUserDetails([]);
        $user->setPaymentDetails([]);
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        return $user;
    }
}
