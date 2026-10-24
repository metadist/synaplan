<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Security\OidcBearerAuthenticator;
use App\Service\Auth\OidcTokenService;
use App\Service\OidcUserService;
use App\Service\PlatformLink\Exception\PlatformDisconnectedException;
use App\Service\PlatformLink\PlatformBearerAttribution;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

final class OidcBearerAuthenticatorTest extends TestCase
{
    public function testBearerIsAcceptedOnChatAndModelsOnly(): void
    {
        $authenticator = $this->authenticator();

        self::assertTrue($authenticator->supports($this->request('POST', '/v1/chat/completions', 'aaa.bbb.ccc')));
        self::assertTrue($authenticator->supports($this->request('GET', '/v1/models', 'aaa.bbb.ccc')));
        self::assertFalse($authenticator->supports($this->request('POST', '/v1/messages', 'aaa.bbb.ccc')));
        self::assertFalse($authenticator->supports($this->request('GET', '/v1/chat/completions', 'aaa.bbb.ccc')));
        self::assertFalse($authenticator->supports($this->request('POST', '/v1/chat/completions', 'sk_abc')));
        self::assertTrue($authenticator->supports($this->request('GET', '/api/v1/auth/me', 'aaa.bbb.ccc')));
    }

    public function testAPausedIdentityIsForbidden(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $claims = ['sub' => 'ada', 'azp' => 'synaplan-opencloud'];

        $tokens = $this->createStub(OidcTokenService::class);
        $tokens->method('validateBearerToken')->willReturn($claims);
        $users = $this->createStub(OidcUserService::class);
        $users->method('findOrCreateFromClaims')->willReturn($user);
        $attribution = $this->createMock(PlatformBearerAttribution::class);
        $attribution->expects($this->once())->method('attribute')->with($user, $claims)->willThrowException(new PlatformDisconnectedException());

        $authenticator = new OidcBearerAuthenticator($tokens, $users, new NullLogger(), $attribution);
        $request = $this->request('GET', '/v1/models', 'aaa.bbb.ccc');

        try {
            $authenticator->authenticate($request);
            self::fail('A paused identity must be refused.');
        } catch (PlatformDisconnectedException $e) {
            $response = $authenticator->onAuthenticationFailure($request, $e);
        }

        self::assertNotNull($response);
        self::assertSame(403, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertSame(PlatformDisconnectedException::CODE, $body['code']);
        self::assertSame(PlatformDisconnectedException::MESSAGE, $body['message']);
    }

    private function authenticator(): OidcBearerAuthenticator
    {
        return new OidcBearerAuthenticator(
            $this->createStub(OidcTokenService::class),
            $this->createStub(OidcUserService::class),
            new NullLogger(),
            $this->createStub(PlatformBearerAttribution::class),
        );
    }

    private function request(string $method, string $path, string $token): Request
    {
        return Request::create($path, $method, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    }
}
