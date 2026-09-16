<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Media;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Media\MediaAccessTokenService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

final class MediaAccessTokenServiceTest extends TestCase
{
    public function testResolvesTheUserItWasMintedFor(): void
    {
        $service = $this->service();

        $token = $service->generate($this->user(42));

        $this->assertSame(42, $service->resolveUserId($token));
    }

    public function testGenerateRejectsAnUnsavedUser(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->generate(new User());
    }

    public function testRejectsATamperedPayload(): void
    {
        $service = $this->service();
        $token = $service->generate($this->user(42));

        [, $signature] = explode('.', $token);
        $forged = $this->encode(['uid' => 43, 'purpose' => 'media_read', 'exp' => time() + 60]).'.'.$signature;

        $this->assertNull($service->resolveUserId($forged));
    }

    public function testRejectsATokenSignedWithADifferentSecret(): void
    {
        $token = $this->service('one-secret')->generate($this->user(42));

        $this->assertNull($this->service('another-secret')->resolveUserId($token));
    }

    /**
     * The signing key is derived from APP_SECRET rather than being it, so a
     * credential minted for another purpose can never be replayed here.
     */
    public function testRejectsATokenSignedWithTheRawAppSecret(): void
    {
        $json = json_encode(['uid' => 42, 'purpose' => 'media_read', 'exp' => time() + 60], JSON_THROW_ON_ERROR);
        $token = $this->encodeJson($json).'.'.hash_hmac('sha256', $json, 'app-secret');

        $this->assertNull($this->service('app-secret')->resolveUserId($token));
    }

    public function testRejectsAnExpiredToken(): void
    {
        $service = $this->service();
        $token = $this->signed($service, ['uid' => 42, 'purpose' => 'media_read', 'exp' => time() - 1]);

        $this->assertNull($service->resolveUserId($token));
    }

    public function testRejectsAnotherPurpose(): void
    {
        $service = $this->service();
        $token = $this->signed($service, ['uid' => 42, 'purpose' => 'access', 'exp' => time() + 60]);

        $this->assertNull($service->resolveUserId($token));
    }

    #[DataProvider('malformedTokens')]
    public function testRejectsMalformedTokens(string $token): void
    {
        $this->assertNull($this->service()->resolveUserId($token));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'no separator' => ['abcdef'];
        yield 'too many parts' => ['a.b.c'];
        yield 'payload not base64' => ['!!!.'.str_repeat('0', 64)];
    }

    public function testResolvesTheUserFromTheRequestQuery(): void
    {
        $user = $this->user(42);
        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->once())->method('find')->with(42)->willReturn($user);

        $service = new MediaAccessTokenService($repository, new NullLogger(), 'test-secret');
        $request = new Request([MediaAccessTokenService::QUERY_PARAM => $service->generate($user)]);

        $this->assertSame($user, $service->resolveUser($request));
    }

    public function testRequestWithoutATokenResolvesToNobody(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $repository->expects($this->never())->method('find');

        $service = new MediaAccessTokenService($repository, new NullLogger(), 'test-secret');

        $this->assertNull($service->resolveUser(new Request()));
    }

    /**
     * `token` is claimed by QueryTokenAuthenticator on the api firewall, so a
     * media token must never be read from it.
     */
    public function testIgnoresTheSessionTokenQueryParameter(): void
    {
        $repository = $this->createMock(UserRepository::class);
        $service = new MediaAccessTokenService($repository, new NullLogger(), 'test-secret');
        $request = new Request(['token' => $service->generate($this->user(42))]);

        $this->assertNull($service->resolveUser($request));
    }

    private function service(string $secret = 'test-secret'): MediaAccessTokenService
    {
        return new MediaAccessTokenService(
            $this->createMock(UserRepository::class),
            new NullLogger(),
            $secret,
        );
    }

    private function user(int $id): User
    {
        $user = new User();
        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setValue($user, $id);

        return $user;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function signed(MediaAccessTokenService $service, array $payload): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $key = hash_hmac('sha256', 'media-access-token/v1', $this->secretOf($service));

        return $this->encodeJson($json).'.'.hash_hmac('sha256', $json, $key);
    }

    private function secretOf(MediaAccessTokenService $service): string
    {
        return (string) (new \ReflectionProperty(MediaAccessTokenService::class, 'appSecret'))->getValue($service);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return $this->encodeJson(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function encodeJson(string $json): string
    {
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }
}
