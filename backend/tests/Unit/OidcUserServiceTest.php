<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\OidcUserService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

class OidcUserServiceTest extends TestCase
{
    private UserRepository&MockObject $userRepository;
    private EntityManagerInterface&MockObject $em;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
    }

    private function createService(
        string $oidcAdminRoles = 'admin,realm-admin,synaplan-admin,administrator',
        string $oidcRoleClaims = 'realm_access.roles,resource_access.{client_id}.roles,groups',
        string $oidcClientId = 'test-client-id',
    ): OidcUserService {
        return new OidcUserService(
            $this->userRepository,
            $this->em,
            new NullLogger(),
            $oidcAdminRoles,
            $oidcRoleClaims,
            $oidcClientId,
        );
    }

    private function getPrivateProperty(object $object, string $property): mixed
    {
        $ref = new \ReflectionProperty($object, $property);

        return $ref->getValue($object);
    }

    private function makeKeycloakUser(string $email, string $userLevel): User
    {
        $user = new User();
        $user->setMail($email);
        $user->setProviderId('keycloak');
        $user->setUserLevel($userLevel);
        $user->setUserDetails([]);
        $user->setPaymentDetails([]);

        return $user;
    }

    private function makeGoogleUser(string $email): User
    {
        $user = new User();
        $user->setMail($email);
        $user->setProviderId('google');
        $user->setUserLevel('NEW');
        $user->setUserDetails([]);
        $user->setPaymentDetails([]);

        return $user;
    }

    // ========== Constructor: OIDC_ADMIN_ROLES parsing ==========

    public function testConstructorParsesCommaSeparatedAdminRoles(): void
    {
        $service = $this->createService('admin,realm-admin,superuser');
        $roles = $this->getPrivateProperty($service, 'adminRoleNames');

        $this->assertSame(['admin', 'realm-admin', 'superuser'], $roles);
    }

    public function testConstructorLowercasesAdminRoles(): void
    {
        $service = $this->createService('Admin,REALM-ADMIN,SuperUser');
        $roles = $this->getPrivateProperty($service, 'adminRoleNames');

        $this->assertSame(['admin', 'realm-admin', 'superuser'], $roles);
    }

    public function testConstructorTrimsWhitespaceFromAdminRoles(): void
    {
        $service = $this->createService(' admin , realm-admin , superuser ');
        $roles = $this->getPrivateProperty($service, 'adminRoleNames');

        $this->assertSame(['admin', 'realm-admin', 'superuser'], $roles);
    }

    public function testConstructorHandlesEmptyAdminRoles(): void
    {
        $service = $this->createService('');
        $roles = $this->getPrivateProperty($service, 'adminRoleNames');

        $this->assertSame([''], $roles);
    }

    public function testConstructorHandlesSingleAdminRole(): void
    {
        $service = $this->createService('administrator');
        $roles = $this->getPrivateProperty($service, 'adminRoleNames');

        $this->assertSame(['administrator'], $roles);
    }

    // ========== Constructor: OIDC_ROLE_CLAIMS parsing ==========

    public function testConstructorParsesDefaultClaimPaths(): void
    {
        $service = $this->createService();
        $paths = $this->getPrivateProperty($service, 'roleClaimPaths');

        $this->assertCount(3, $paths);
        $this->assertSame(['realm_access', 'roles'], $paths[0]);
        $this->assertSame(['resource_access', 'test-client-id', 'roles'], $paths[1]);
        $this->assertSame(['groups'], $paths[2]);
    }

    public function testConstructorParsesCustomClaimPaths(): void
    {
        $service = $this->createService(oidcRoleClaims: 'roles,custom.nested.path');
        $paths = $this->getPrivateProperty($service, 'roleClaimPaths');

        $this->assertCount(2, $paths);
        $this->assertSame(['roles'], $paths[0]);
        $this->assertSame(['custom', 'nested', 'path'], $paths[1]);
    }

    public function testConstructorReplacesClientIdPlaceholder(): void
    {
        $service = $this->createService(oidcRoleClaims: 'resource_access.{client_id}.roles');
        $paths = $this->getPrivateProperty($service, 'roleClaimPaths');

        $this->assertCount(1, $paths);
        $this->assertSame(['resource_access', 'test-client-id', 'roles'], $paths[0]);
    }

    public function testConstructorTrimsWhitespaceInClaimPaths(): void
    {
        $service = $this->createService(oidcRoleClaims: ' roles , groups ');
        $paths = $this->getPrivateProperty($service, 'roleClaimPaths');

        $this->assertCount(2, $paths);
        $this->assertSame(['roles'], $paths[0]);
        $this->assertSame(['groups'], $paths[1]);
    }

    public function testConstructorSkipsEmptyClaimPathSegments(): void
    {
        $service = $this->createService(oidcRoleClaims: 'roles,,groups,');
        $paths = $this->getPrivateProperty($service, 'roleClaimPaths');

        $this->assertCount(2, $paths);
        $this->assertSame(['roles'], $paths[0]);
        $this->assertSame(['groups'], $paths[1]);
    }

    public function testConstructorHandlesEscapedDotsInClaimPaths(): void
    {
        $service = $this->createService(oidcRoleClaims: 'https://myapp\.com/roles');
        $paths = $this->getPrivateProperty($service, 'roleClaimPaths');

        $this->assertCount(1, $paths);
        $this->assertSame(['https://myapp.com/roles'], $paths[0]);
    }

    // ========== findOrCreateFromClaims: admin promotion/demotion ==========

    public function testPromotesToAdminWhenOidcRoleMatches(): void
    {
        $service = $this->createService('administrator');
        $user = $this->makeKeycloakUser('test@example.com', 'FREE');

        $this->userRepository->method('findOneBy')->willReturn($user);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $result = $service->findOrCreateFromClaims([
            'sub' => 'sub-123',
            'email' => 'test@example.com',
            'realm_access' => ['roles' => ['administrator', 'default-roles-synaplan']],
        ]);

        $this->assertSame('ADMIN', $result->getUserLevel());
    }

    public function testDemotesAdminWhenOidcRoleRemoved(): void
    {
        $service = $this->createService('administrator');
        $user = $this->makeKeycloakUser('test@example.com', 'ADMIN');

        $this->userRepository->method('findOneBy')->willReturn($user);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $result = $service->findOrCreateFromClaims([
            'sub' => 'sub-123',
            'email' => 'test@example.com',
            'realm_access' => ['roles' => ['default-roles-synaplan']],
        ]);

        $this->assertSame('NEW', $result->getUserLevel());
    }

    public function testKeepsAdminWhenOidcRoleStillPresent(): void
    {
        $service = $this->createService('administrator');
        $user = $this->makeKeycloakUser('test@example.com', 'ADMIN');

        $this->userRepository->method('findOneBy')->willReturn($user);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $result = $service->findOrCreateFromClaims([
            'sub' => 'sub-123',
            'email' => 'test@example.com',
            'realm_access' => ['roles' => ['administrator']],
        ]);

        $this->assertSame('ADMIN', $result->getUserLevel());
    }

    public function testDoesNotPromoteWhenNoMatchingRole(): void
    {
        $service = $this->createService('administrator');
        $user = $this->makeKeycloakUser('test@example.com', 'NEW');

        $this->userRepository->method('findOneBy')->willReturn($user);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $result = $service->findOrCreateFromClaims([
            'sub' => 'sub-123',
            'email' => 'test@example.com',
            'realm_access' => ['roles' => ['viewer', 'editor']],
        ]);

        $this->assertSame('NEW', $result->getUserLevel());
    }

    public function testAdminMatchIsCaseInsensitive(): void
    {
        $service = $this->createService('administrator');
        $user = $this->makeKeycloakUser('test@example.com', 'FREE');

        $this->userRepository->method('findOneBy')->willReturn($user);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $result = $service->findOrCreateFromClaims([
            'sub' => 'sub-123',
            'email' => 'test@example.com',
            'realm_access' => ['roles' => ['Administrator']],
        ]);

        $this->assertSame('ADMIN', $result->getUserLevel());
    }

    public function testChecksResourceAccessRoles(): void
    {
        $service = $this->createService('administrator');
        $user = $this->makeKeycloakUser('test@example.com', 'FREE');

        $this->userRepository->method('findOneBy')->willReturn($user);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $result = $service->findOrCreateFromClaims([
            'sub' => 'sub-123',
            'email' => 'test@example.com',
            'resource_access' => [
                'test-client-id' => ['roles' => ['administrator']],
            ],
        ]);

        $this->assertSame('ADMIN', $result->getUserLevel());
    }

    public function testChecksGroupsClaim(): void
    {
        $service = $this->createService('administrator');
        $user = $this->makeKeycloakUser('test@example.com', 'FREE');

        $this->userRepository->method('findOneBy')->willReturn($user);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $result = $service->findOrCreateFromClaims([
            'sub' => 'sub-123',
            'email' => 'test@example.com',
            'groups' => ['administrator', '/users'],
        ]);

        $this->assertSame('ADMIN', $result->getUserLevel());
    }

    // ========== findOrCreateFromClaims: custom claim paths ==========

    public function testExtractsFlatRolesClaim(): void
    {
        $service = $this->createService(
            oidcAdminRoles: 'administrator',
            oidcRoleClaims: 'roles',
        );
        $user = $this->makeKeycloakUser('test@example.com', 'NEW');

        $this->userRepository->method('findOneBy')->willReturn($user);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $result = $service->findOrCreateFromClaims([
            'sub' => 'sub-123',
            'email' => 'test@example.com',
            'roles' => ['administrator', 'viewer'],
        ]);

        $this->assertSame('ADMIN', $result->getUserLevel());
    }

    public function testExtractsEscapedDotClaim(): void
    {
        $service = $this->createService(
            oidcAdminRoles: 'admin',
            oidcRoleClaims: 'https://myapp\.com/roles',
        );
        $user = $this->makeKeycloakUser('test@example.com', 'NEW');

        $this->userRepository->method('findOneBy')->willReturn($user);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $result = $service->findOrCreateFromClaims([
            'sub' => 'sub-123',
            'email' => 'test@example.com',
            'https://myapp.com/roles' => ['admin', 'user'],
        ]);

        $this->assertSame('ADMIN', $result->getUserLevel());
    }

    public function testFindOrCreateFromClaimsThrowsGenericMessageWhenEmailBoundToNonKeycloakProvider(): void
    {
        $service = $this->createService();
        $googleUser = $this->makeGoogleUser('overlap@example.com');

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn(null);
        $qb->method('getQuery')->willReturn($query);
        $this->userRepository->method('createQueryBuilder')->willReturn($qb);
        $this->userRepository->method('findOneBy')->with(['mail' => 'overlap@example.com'])->willReturn($googleUser);

        $this->em->expects($this->never())->method('persist');

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Authentication failed.');

        $service->findOrCreateFromClaims([
            'sub' => 'oidc-sub-xyz',
            'email' => 'overlap@example.com',
        ]);
    }
}
