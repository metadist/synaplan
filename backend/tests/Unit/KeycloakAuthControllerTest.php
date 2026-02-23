<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Controller\KeycloakAuthController;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\OAuthStateService;
use App\Service\OidcTokenService;
use App\Service\TokenService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class KeycloakAuthControllerTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;
    private UserRepository&MockObject $userRepository;
    private EntityManagerInterface&MockObject $em;
    private TokenService&MockObject $tokenService;
    private OidcTokenService&MockObject $oidcTokenService;
    private OAuthStateService $oauthStateService;
    private NullLogger $logger;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->tokenService = $this->createMock(TokenService::class);
        $this->oidcTokenService = $this->createMock(OidcTokenService::class);
        $this->logger = new NullLogger();
        // OAuthStateService is final — use a real instance with a dummy secret
        $this->oauthStateService = new OAuthStateService($this->logger, 'test-secret');
    }

    private function createController(string $oidcAdminRoles = 'admin,realm-admin,synaplan-admin,administrator'): KeycloakAuthController
    {
        return new KeycloakAuthController(
            $this->httpClient,
            $this->userRepository,
            $this->em,
            $this->tokenService,
            $this->oidcTokenService,
            $this->oauthStateService,
            $this->logger,
            'test-client-id',
            'test-client-secret',
            'https://keycloak.example.com/realms/test',
            $oidcAdminRoles,
            'https://app.example.com',
            'https://app.example.com',
        );
    }

    /**
     * Invoke a private method via reflection for unit testing.
     */
    private function invokePrivateMethod(object $object, string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod($object, $method);

        return $ref->invoke($object, ...$args);
    }

    private function getPrivateProperty(object $object, string $property): mixed
    {
        $ref = new \ReflectionProperty($object, $property);

        return $ref->getValue($object);
    }

    // ========== Constructor: OIDC_ADMIN_ROLES parsing ==========

    public function testConstructorParsesCommaSeparatedAdminRoles(): void
    {
        $controller = $this->createController('admin,realm-admin,superuser');
        $roles = $this->getPrivateProperty($controller, 'adminRoleNames');

        $this->assertSame(['admin', 'realm-admin', 'superuser'], $roles);
    }

    public function testConstructorLowercasesAdminRoles(): void
    {
        $controller = $this->createController('Admin,REALM-ADMIN,SuperUser');
        $roles = $this->getPrivateProperty($controller, 'adminRoleNames');

        $this->assertSame(['admin', 'realm-admin', 'superuser'], $roles);
    }

    public function testConstructorTrimsWhitespaceFromAdminRoles(): void
    {
        $controller = $this->createController(' admin , realm-admin , superuser ');
        $roles = $this->getPrivateProperty($controller, 'adminRoleNames');

        $this->assertSame(['admin', 'realm-admin', 'superuser'], $roles);
    }

    public function testConstructorUsesDefaultsWhenAdminRolesEmpty(): void
    {
        $controller = $this->createController('');
        $roles = $this->getPrivateProperty($controller, 'adminRoleNames');

        $this->assertSame(['admin', 'realm-admin', 'synaplan-admin', 'administrator'], $roles);
    }

    public function testConstructorHandlesSingleAdminRole(): void
    {
        $controller = $this->createController('administrator');
        $roles = $this->getPrivateProperty($controller, 'adminRoleNames');

        $this->assertSame(['administrator'], $roles);
    }

    // ========== decodeJwtPayload ==========

    public function testDecodeJwtPayloadReturnsClaimsFromValidJwt(): void
    {
        $controller = $this->createController();
        $payload = ['sub' => '123', 'realm_access' => ['roles' => ['admin']]];
        $jwt = $this->buildJwt($payload);

        $result = $this->invokePrivateMethod($controller, 'decodeJwtPayload', $jwt);

        $this->assertIsArray($result);
        $this->assertSame('123', $result['sub']);
        $this->assertSame(['admin'], $result['realm_access']['roles']);
    }

    public function testDecodeJwtPayloadReturnsNullForInvalidJwt(): void
    {
        $controller = $this->createController();

        $this->assertNull($this->invokePrivateMethod($controller, 'decodeJwtPayload', 'not-a-jwt'));
        $this->assertNull($this->invokePrivateMethod($controller, 'decodeJwtPayload', 'only.two'));
        $this->assertNull($this->invokePrivateMethod($controller, 'decodeJwtPayload', ''));
    }

    public function testDecodeJwtPayloadReturnsNullForNonJsonPayload(): void
    {
        $controller = $this->createController();
        $badPayload = base64_encode('not json');
        $jwt = "header.{$badPayload}.signature";

        $this->assertNull($this->invokePrivateMethod($controller, 'decodeJwtPayload', $jwt));
    }

    public function testDecodeJwtPayloadHandlesUrlSafeBase64(): void
    {
        $controller = $this->createController();
        $payload = ['key' => 'value with special chars: +/='];
        $jwt = $this->buildJwt($payload);

        $result = $this->invokePrivateMethod($controller, 'decodeJwtPayload', $jwt);

        $this->assertIsArray($result);
        $this->assertSame('value with special chars: +/=', $result['key']);
    }

    // ========== findOrCreateUser: admin promotion/demotion ==========

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

    public function testFindOrCreateUserPromotesToAdminWhenOidcRoleMatches(): void
    {
        $controller = $this->createController('administrator');
        $user = $this->makeKeycloakUser('test@example.com', 'FREE');

        $this->userRepository->method('findOneBy')->willReturn($user);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $userInfo = [
            'sub' => 'sub-123',
            'email' => 'test@example.com',
            'realm_access' => ['roles' => ['administrator', 'default-roles-synaplan']],
        ];

        $result = $this->invokePrivateMethod($controller, 'findOrCreateUser', $userInfo, null);

        $this->assertSame('ADMIN', $result->getUserLevel());
    }

    public function testFindOrCreateUserDemotesAdminWhenOidcRoleRemoved(): void
    {
        $controller = $this->createController('administrator');
        $user = $this->makeKeycloakUser('test@example.com', 'ADMIN');

        $this->userRepository->method('findOneBy')->willReturn($user);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $userInfo = [
            'sub' => 'sub-123',
            'email' => 'test@example.com',
            'realm_access' => ['roles' => ['default-roles-synaplan']],
        ];

        $result = $this->invokePrivateMethod($controller, 'findOrCreateUser', $userInfo, null);

        $this->assertSame('NEW', $result->getUserLevel());
    }

    public function testFindOrCreateUserKeepsAdminWhenOidcRoleStillPresent(): void
    {
        $controller = $this->createController('administrator');
        $user = $this->makeKeycloakUser('test@example.com', 'ADMIN');

        $this->userRepository->method('findOneBy')->willReturn($user);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $userInfo = [
            'sub' => 'sub-123',
            'email' => 'test@example.com',
            'realm_access' => ['roles' => ['administrator']],
        ];

        $result = $this->invokePrivateMethod($controller, 'findOrCreateUser', $userInfo, null);

        $this->assertSame('ADMIN', $result->getUserLevel());
    }

    public function testFindOrCreateUserDoesNotPromoteWhenNoMatchingRole(): void
    {
        $controller = $this->createController('administrator');
        $user = $this->makeKeycloakUser('test@example.com', 'NEW');

        $this->userRepository->method('findOneBy')->willReturn($user);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $userInfo = [
            'sub' => 'sub-123',
            'email' => 'test@example.com',
            'realm_access' => ['roles' => ['viewer', 'editor']],
        ];

        $result = $this->invokePrivateMethod($controller, 'findOrCreateUser', $userInfo, null);

        $this->assertSame('NEW', $result->getUserLevel());
    }

    public function testFindOrCreateUserAdminMatchIsCaseInsensitive(): void
    {
        $controller = $this->createController('administrator');
        $user = $this->makeKeycloakUser('test@example.com', 'FREE');

        $this->userRepository->method('findOneBy')->willReturn($user);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $userInfo = [
            'sub' => 'sub-123',
            'email' => 'test@example.com',
            'realm_access' => ['roles' => ['Administrator']],
        ];

        $result = $this->invokePrivateMethod($controller, 'findOrCreateUser', $userInfo, null);

        $this->assertSame('ADMIN', $result->getUserLevel());
    }

    public function testFindOrCreateUserChecksResourceAccessRoles(): void
    {
        $controller = $this->createController('administrator');
        $user = $this->makeKeycloakUser('test@example.com', 'FREE');

        $this->userRepository->method('findOneBy')->willReturn($user);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $userInfo = [
            'sub' => 'sub-123',
            'email' => 'test@example.com',
            'resource_access' => [
                'test-client-id' => ['roles' => ['administrator']],
            ],
        ];

        $result = $this->invokePrivateMethod($controller, 'findOrCreateUser', $userInfo, null);

        $this->assertSame('ADMIN', $result->getUserLevel());
    }

    public function testFindOrCreateUserChecksGroupsClaim(): void
    {
        $controller = $this->createController('administrator');
        $user = $this->makeKeycloakUser('test@example.com', 'FREE');

        $this->userRepository->method('findOneBy')->willReturn($user);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $userInfo = [
            'sub' => 'sub-123',
            'email' => 'test@example.com',
            'groups' => ['administrator', '/users'],
        ];

        $result = $this->invokePrivateMethod($controller, 'findOrCreateUser', $userInfo, null);

        $this->assertSame('ADMIN', $result->getUserLevel());
    }

    // ========== Helpers ==========

    /**
     * Build a minimal JWT string with the given payload claims.
     */
    private function buildJwt(array $payload): string
    {
        $header = rtrim(strtr(base64_encode('{"alg":"RS256","typ":"JWT"}'), '+/', '-_'), '=');
        $body = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode('fake-signature'), '+/', '-_'), '=');

        return "{$header}.{$body}.{$signature}";
    }
}
