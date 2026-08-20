<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Repository\UserRepository;
use App\Service\Auth\AuthCookieFactory;
use App\Service\JwtValidator;
use App\Service\OidcTokenService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Login-path claim resolution (#1520).
 *
 * Login used to resolve the user through the userinfo endpoint plus an
 * unverified JWT decode, while the refresh path validated the same token
 * locally including a strict audience check. A client without an audience
 * mapper therefore passed login and then failed every refresh five minutes
 * later. These tests pin the mechanism to the token type so both paths agree.
 */
final class OidcLoginClaimsTest extends TestCase
{
    private const REALM = 'https://keycloak.example.com/realms/test';
    private const JWKS = self::REALM.'/protocol/openid-connect/certs';
    private const USERINFO = self::REALM.'/protocol/openid-connect/userinfo';

    /** @var list<string> */
    private array $requestedUrls = [];

    /**
     * Wires the service against a mock IdP that answers discovery and userinfo,
     * recording which endpoints were hit. Returns the JwtValidator mock so each
     * test can decide what local validation concludes.
     *
     * @param array<string, mixed> $userInfo         claims the userinfo endpoint returns
     * @param int                  $userInfoHttpCode use a non-2xx code to simulate an unreachable userinfo endpoint
     *
     * @return array{OidcTokenService, MockObject} the service and its JwtValidator double
     */
    private function createService(
        array $userInfo = [],
        ?LoggerInterface $logger = null,
        int $userInfoHttpCode = 200,
    ): array {
        $this->requestedUrls = [];

        $httpClient = new MockHttpClient(function (string $method, string $url) use ($userInfo, $userInfoHttpCode): ResponseInterface {
            $this->requestedUrls[] = $url;

            if (str_contains($url, '.well-known/openid-configuration')) {
                return new MockResponse((string) json_encode([
                    'issuer' => self::REALM,
                    'jwks_uri' => self::JWKS,
                    'userinfo_endpoint' => self::USERINFO,
                ]));
            }

            return new MockResponse(
                (string) json_encode($userInfo),
                ['http_code' => $userInfoHttpCode],
            );
        });

        $jwtValidator = $this->createMock(JwtValidator::class);

        $service = new OidcTokenService(
            $httpClient,
            $this->createStub(UserRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(Connection::class),
            $logger ?? new NullLogger(),
            $jwtValidator,
            new AuthCookieFactory('test', 'https://synaplan.example.com'),
            'synaplan-app',
            'test-client-secret',
            self::REALM,
        );

        return [$service, $jwtValidator];
    }

    private function jwt(): string
    {
        $encode = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        return $encode('{"alg":"RS256","typ":"JWT"}')
            .'.'.$encode('{"sub":"kc-user-1"}')
            .'.'.$encode('signature');
    }

    private function userInfoWasCalled(): bool
    {
        foreach ($this->requestedUrls as $url) {
            if (str_contains($url, '/userinfo')) {
                return true;
            }
        }

        return false;
    }

    public function testJwtAccessTokenIsValidatedLocallyAndSkipsUserinfo(): void
    {
        [$service, $jwtValidator] = $this->createService();

        $jwtValidator->expects($this->once())
            ->method('validateToken')
            ->with(
                token: $this->jwt(),
                jwksUri: self::JWKS,
                expectedIssuer: self::REALM,
                expectedAudience: 'synaplan-app',
            )
            ->willReturn([
                'sub' => 'kc-user-1',
                'email' => 'user@example.com',
                'realm_access' => ['roles' => ['admin']],
            ]);

        $claims = $service->resolveLoginClaims($this->jwt());

        self::assertNotNull($claims);
        self::assertSame('kc-user-1', $claims['sub']);
        self::assertSame(['roles' => ['admin']], $claims['realm_access']);
        self::assertFalse($this->userInfoWasCalled(), 'A validated JWT needs no userinfo round-trip');
    }

    /**
     * The regression this issue is about: a token the refresh path would reject
     * must not be accepted at login either.
     */
    public function testLoginIsRejectedWhenTheAccessTokenFailsLocalValidation(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())
            ->method('error')
            ->with($this->stringContains('failed local JWT validation'), $this->anything());

        [$service, $jwtValidator] = $this->createService(logger: $logger);

        $jwtValidator->method('validateToken')->willReturn(null);

        self::assertNull($service->resolveLoginClaims($this->jwt()));
    }

    public function testOpaqueAccessTokenIsResolvedThroughUserinfo(): void
    {
        [$service, $jwtValidator] = $this->createService([
            'sub' => 'kc-user-2',
            'email' => 'opaque@example.com',
        ]);

        $jwtValidator->expects($this->never())->method('validateToken');

        $claims = $service->resolveLoginClaims('a1b2c3-opaque-token');

        self::assertNotNull($claims);
        self::assertSame('kc-user-2', $claims['sub']);
        self::assertSame('opaque@example.com', $claims['email']);
        self::assertTrue($this->userInfoWasCalled(), 'An opaque token can only be resolved by the IdP');
    }

    /**
     * Three dot-separated segments alone do not make a JWT; a token whose
     * segments are not base64url must take the opaque path rather than be
     * handed to the validator.
     */
    public function testTokenWithThreeNonBase64SegmentsCountsAsOpaque(): void
    {
        [$service, $jwtValidator] = $this->createService(['sub' => 'kc-user-3']);

        $jwtValidator->expects($this->never())->method('validateToken');

        $claims = $service->resolveLoginClaims('not.a.jwt!');

        self::assertNotNull($claims);
        self::assertSame('kc-user-3', $claims['sub']);
    }

    public function testProfileClaimsAreToppedUpFromUserinfoButValidatedClaimsWin(): void
    {
        [$service, $jwtValidator] = $this->createService([
            'sub' => 'someone-else',
            'email' => 'from-userinfo@example.com',
            'given_name' => 'Ada',
        ]);

        // A client whose mappers keep profile claims out of the access token.
        $jwtValidator->method('validateToken')->willReturn([
            'sub' => 'kc-user-4',
            'realm_access' => ['roles' => ['admin']],
        ]);

        $claims = $service->resolveLoginClaims($this->jwt());

        self::assertNotNull($claims);
        self::assertSame('from-userinfo@example.com', $claims['email']);
        self::assertSame('Ada', $claims['given_name']);
        self::assertSame('kc-user-4', $claims['sub'], 'The validated token, not userinfo, decides who the user is');
        self::assertTrue($this->userInfoWasCalled());
    }

    public function testUserinfoIsNotCalledWhenTheTokenAlreadyIdentifiesTheUser(): void
    {
        [$service, $jwtValidator] = $this->createService();

        $jwtValidator->method('validateToken')->willReturn([
            'sub' => 'kc-user-5',
            'preferred_username' => 'ada',
        ]);

        $claims = $service->resolveLoginClaims($this->jwt());

        self::assertNotNull($claims);
        self::assertSame('ada', $claims['preferred_username']);
        self::assertFalse($this->userInfoWasCalled());
    }

    public function testUnreachableUserinfoStillYieldsTheValidatedClaims(): void
    {
        [$service, $jwtValidator] = $this->createService(userInfoHttpCode: 500);

        $jwtValidator->method('validateToken')->willReturn(['sub' => 'kc-user-6']);

        self::assertSame(['sub' => 'kc-user-6'], $service->resolveLoginClaims($this->jwt()));
    }
}
