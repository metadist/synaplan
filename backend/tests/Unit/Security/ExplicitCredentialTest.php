<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\ApiKey;
use App\Entity\User;
use App\Security\ExplicitCredential;
use App\Security\QueryTokenAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ExplicitCredentialTest extends TestCase
{
    public function testCookieAloneIsNotExplicit(): void
    {
        $request = Request::create('/api/v1/messages/stream?message=hi&chatId=1');
        $request->cookies->set('access_token', 'anything');

        self::assertFalse(ExplicitCredential::accompanies($request, $this->user(7)));
    }

    public function testNoCallerIsNotExplicit(): void
    {
        $request = Request::create('/api/v1/messages/stream');
        $request->headers->set('Authorization', 'Bearer something');

        self::assertFalse(ExplicitCredential::accompanies($request, null));
    }

    public function testAuthorizationHeaderIsExplicit(): void
    {
        $request = Request::create('/api/v1/messages/stream');
        $request->headers->set('Authorization', 'Bearer something');

        self::assertTrue(ExplicitCredential::accompanies($request, $this->user(7)));
    }

    public function testApiKeyHeaderIsExplicit(): void
    {
        $request = Request::create('/api/v1/messages/stream');
        $request->headers->set('X-API-Key', 'sk_test');

        self::assertTrue(ExplicitCredential::accompanies($request, $this->user(7)));
    }

    public function testQueryTokenCountsOnlyForItsOwnAccount(): void
    {
        $request = Request::create('/api/v1/messages/stream?token=abc');
        $request->attributes->set(QueryTokenAuthenticator::USER_ID_ATTRIBUTE, 7);

        self::assertTrue(ExplicitCredential::accompanies($request, $this->user(7)));
        self::assertFalse(ExplicitCredential::accompanies($request, $this->user(8)));
    }

    public function testAnUnverifiedQueryTokenIsNotExplicit(): void
    {
        $request = Request::create('/api/v1/messages/stream?token=abc&api_key=sk_guess');

        self::assertFalse(ExplicitCredential::accompanies($request, $this->user(7)));
    }

    public function testQueryApiKeyCountsOnlyForItsOwner(): void
    {
        $apiKey = self::createStub(ApiKey::class);
        $apiKey->method('getOwnerId')->willReturn(7);

        $request = Request::create('/api/v1/messages/stream?api_key=sk_test');
        $request->attributes->set('api_key', $apiKey);

        self::assertTrue(ExplicitCredential::accompanies($request, $this->user(7)));
        self::assertFalse(ExplicitCredential::accompanies($request, $this->user(8)));
    }

    private function user(int $id): User
    {
        $user = self::createStub(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }
}
