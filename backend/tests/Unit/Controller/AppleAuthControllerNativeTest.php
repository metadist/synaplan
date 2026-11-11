<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\AppleAuthController;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\AccountAccess;
use App\Service\Auth\AppleClientSecretGenerator;
use App\Service\Auth\AppleIdentityTokenVerifier;
use App\Service\Auth\OAuthLoginResponder;
use App\Service\Auth\OAuthSignupGate;
use App\Service\Auth\TokenService;
use App\Service\OAuth\OAuthStateService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AppleAuthControllerNativeTest extends TestCase
{
    public function testASuspendedAccountIsRefusedBeforeATokenIsMinted(): void
    {
        $user = $this->createStub(User::class);
        $user->method('isManagedExternally')->willReturn(false);
        $user->method('getUserDetails')->willReturn([]);
        $user->method('isEmailVerified')->willReturn(true);
        $user->method('canSignIn')->willReturn(false);

        $users = $this->createStub(UserRepository::class);
        $users->method('findByAppleSub')->willReturn($user);

        $verifier = $this->createStub(AppleIdentityTokenVerifier::class);
        $verifier->method('verify')->willReturn([
            'sub' => 'apple-sub',
            'email' => 'ada@example.com',
            'emailVerified' => true,
            'isPrivateEmail' => false,
        ]);

        $tokens = $this->createMock(TokenService::class);
        $tokens->expects(self::never())->method('generateAccessToken');
        $tokens->expects(self::never())->method('generateRefreshToken');

        $controller = new AppleAuthController(
            new MockHttpClient(),
            $users,
            $this->createStub(OAuthStateService::class),
            $this->createStub(OAuthLoginResponder::class),
            $this->createStub(OAuthSignupGate::class),
            $this->createStub(AppleClientSecretGenerator::class),
            $verifier,
            $tokens,
            new NullLogger(),
            'client',
            'http://localhost',
        );

        $response = $controller->native(Request::create('/api/v1/auth/apple/native', 'POST', content: json_encode(['identityToken' => 'token'], JSON_THROW_ON_ERROR)));
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame(AccountAccess::SUSPENDED, $body['code']);
        self::assertSame(AccountAccess::SUSPENDED_MESSAGE, $body['error']);
    }
}
