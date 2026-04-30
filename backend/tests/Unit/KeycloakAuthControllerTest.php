<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Controller\KeycloakAuthController;
use App\Service\OAuthStateService;
use App\Service\OidcTokenService;
use App\Service\OidcUserService;
use App\Service\TokenService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class KeycloakAuthControllerTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;
    private TokenService&MockObject $tokenService;
    private OidcTokenService&MockObject $oidcTokenService;
    private OidcUserService&MockObject $oidcUserService;
    private OAuthStateService $oauthStateService;
    private NullLogger $logger;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->tokenService = $this->createMock(TokenService::class);
        $this->oidcTokenService = $this->createMock(OidcTokenService::class);
        $this->oidcUserService = $this->createMock(OidcUserService::class);
        $this->logger = new NullLogger();
        // OAuthStateService is final — use a real instance with a dummy secret
        $this->oauthStateService = new OAuthStateService($this->logger, 'test-secret');
    }

    private function createController(
        string $oidcScopes = 'openid email profile offline_access',
    ): KeycloakAuthController {
        // ImpersonationService is only consulted on successful logins to
        // wipe orphan stash cookies — we mock it as a no-op so the existing
        // test cases stay focused on the OIDC PKCE / token-exchange paths
        // they were written for.
        $impersonationService = $this->createMock(\App\Service\ImpersonationService::class);

        return new KeycloakAuthController(
            $this->httpClient,
            $this->tokenService,
            $this->oidcTokenService,
            $this->oidcUserService,
            $this->oauthStateService,
            $impersonationService,
            $this->logger,
            'test-client-id',
            'test-client-secret',
            'https://keycloak.example.com/realms/test',
            $oidcScopes,
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
