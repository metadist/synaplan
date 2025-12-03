<?php

namespace App\Tests\Unit;

use App\Entity\ApiKey;
use App\Entity\User;
use App\Repository\ApiKeyRepository;
use App\Security\ApiKeyAuthenticator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

class ApiKeyAuthenticatorTest extends TestCase
{
    private ApiKeyRepository $apiKeyRepository;
    private LoggerInterface $logger;
    private ApiKeyAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->apiKeyRepository = $this->createMock(ApiKeyRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->authenticator = new ApiKeyAuthenticator(
            $this->apiKeyRepository,
            $this->logger
        );
    }

    public function testSupportsReturnsTrueWithHeaderApiKey(): void
    {
        $request = new Request();
        $request->headers->set('X-API-Key', 'test-key');

        $this->assertTrue($this->authenticator->supports($request));
    }

    public function testSupportsReturnsTrueWithQueryApiKey(): void
    {
        $request = new Request(['api_key' => 'test-key']);

        $this->assertTrue($this->authenticator->supports($request));
    }

    public function testSupportsReturnsFalseWithoutApiKey(): void
    {
        $request = new Request();

        $this->assertFalse($this->authenticator->supports($request));
    }

    public function testAuthenticateThrowsExceptionForInvalidKey(): void
    {
        $request = new Request();
        $request->headers->set('X-API-Key', 'invalid-key');

        $this->apiKeyRepository
            ->expects($this->once())
            ->method('findActiveByKey')
            ->with('invalid-key')
            ->willReturn(null);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid or inactive API key');

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionForInactiveKey(): void
    {
        $apiKey = $this->createMock(ApiKey::class);
        $apiKey->method('isActive')->willReturn(false);
        $apiKey->method('getId')->willReturn(1);
        $apiKey->method('getOwnerId')->willReturn(10);

        $request = new Request();
        $request->headers->set('X-API-Key', 'inactive-key');

        $this->apiKeyRepository
            ->method('findActiveByKey')
            ->willReturn($apiKey);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('API key is inactive');

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateSucceedsWithValidKey(): void
    {
        $apiKey = $this->createMock(ApiKey::class);
        $apiKey->method('isActive')->willReturn(true);
        $apiKey->method('getId')->willReturn(1);
        $apiKey->method('getOwnerId')->willReturn(10);
        $apiKey->method('getName')->willReturn('Test Key');
        $apiKey->method('getScopes')->willReturn(['webhooks:*']);

        $request = new Request();
        $request->headers->set('X-API-Key', 'valid-key');

        $this->apiKeyRepository
            ->method('findActiveByKey')
            ->willReturn($apiKey);

        $this->apiKeyRepository
            ->expects($this->once())
            ->method('save')
            ->with($apiKey, false);

        $passport = $this->authenticator->authenticate($request);

        $this->assertInstanceOf(\Symfony\Component\Security\Http\Authenticator\Passport\Passport::class, $passport);
        $this->assertSame($apiKey, $request->attributes->get('api_key'));
    }

    public function testAuthenticatePrefersSupportedByHeader(): void
    {
        $request = new Request(['api_key' => 'query-key']);
        $request->headers->set('X-API-Key', 'header-key');

        $this->apiKeyRepository
            ->expects($this->once())
            ->method('findActiveByKey')
            ->with('header-key'); // Should use header, not query

        try {
            $this->authenticator->authenticate($request);
        } catch (AuthenticationException $e) {
            // Expected since we're mocking
        }
    }

    public function testAuthenticateLogsSuccessfulAuth(): void
    {
        $apiKey = $this->createMock(ApiKey::class);
        $apiKey->method('isActive')->willReturn(true);
        $apiKey->method('getId')->willReturn(1);
        $apiKey->method('getOwnerId')->willReturn(10);
        $apiKey->method('getName')->willReturn('Test Key');
        $apiKey->method('getScopes')->willReturn(['webhooks:*']);

        $request = new Request();
        $request->headers->set('X-API-Key', 'valid-key');

        $this->apiKeyRepository->method('findActiveByKey')->willReturn($apiKey);
        $this->apiKeyRepository->method('save');

        $this->logger
            ->expects($this->exactly(2))
            ->method('info');

        $this->authenticator->authenticate($request);
    }
}

