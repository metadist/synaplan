<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\KeycloakAuthController;
use App\Service\Auth\ImpersonationService;
use App\Service\Auth\OAuthLoginResponder;
use App\Service\Auth\OidcAuthorizeParams;
use App\Service\Auth\OidcLoginState;
use App\Service\Auth\OidcScopeResolver;
use App\Service\Auth\OidcTokenService;
use App\Service\Auth\TokenService;
use App\Service\OidcUserService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

final class KeycloakLoginStateTest extends TestCase
{
    public function testTheAuthorizeRedirectCarriesAShortStateAndNotTheVerifier(): void
    {
        $state = new OidcLoginState(new ArrayAdapter());
        $controller = $this->controller($state, new MockHttpClient([
            new MockResponse(json_encode([
                'authorization_endpoint' => 'https://idp.example/auth',
                'scopes_supported' => ['openid', 'email', 'profile', 'offline_access'],
            ], JSON_THROW_ON_ERROR)),
        ]));

        $response = $controller->login(Request::create('/api/v1/auth/keycloak/login', 'GET', [
            'native' => '1',
            'language' => 'de-DE',
        ]));

        self::assertInstanceOf(RedirectResponse::class, $response);
        $location = $response->getTargetUrl();
        $params = [];
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);

        $issued = $params['state'] ?? null;
        self::assertIsString($issued);
        self::assertSame(32, strlen($issued));
        self::assertLessThan(80, strlen($issued));

        $attempt = $state->consume($issued);
        self::assertIsArray($attempt);
        self::assertStringNotContainsString($attempt['pkce_verifier'], $location);
        self::assertTrue($attempt['native']);
        self::assertSame('de', $attempt['language']);
        self::assertSame('openid email profile offline_access', $params['scope'] ?? null);
        self::assertSame($this->challenge($attempt['pkce_verifier']), $params['code_challenge'] ?? null);
        self::assertSame('S256', $params['code_challenge_method'] ?? null);
        self::assertNull($state->consume($issued));
    }

    private function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function controller(OidcLoginState $state, MockHttpClient $http): KeycloakAuthController
    {
        $authorize = $this->createStub(OidcAuthorizeParams::class);
        $authorize->method('params')->willReturn([]);

        return new KeycloakAuthController(
            $http,
            $this->createStub(TokenService::class),
            $this->createStub(OidcTokenService::class),
            $this->createStub(OidcUserService::class),
            $state,
            $this->createStub(OAuthLoginResponder::class),
            $this->createStub(ImpersonationService::class),
            new NullLogger(),
            'client-id',
            'client-secret',
            'https://idp.example',
            'openid email profile offline_access',
            'https://api.example.com',
            'https://app.example.com',
            new OidcScopeResolver(),
            $authorize,
        );
    }
}
