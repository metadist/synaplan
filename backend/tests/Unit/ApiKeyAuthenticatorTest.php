<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ApiKey;
use App\Repository\ApiKeyRepository;
use App\Security\ApiKeyAuthenticator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

class ApiKeyAuthenticatorTest extends TestCase
{
    private ApiKeyRepository&MockObject $apiKeyRepository;
    private LoggerInterface&MockObject $logger;
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

    public function testSupportsReturnsTrueWithBearerOnV1Route(): void
    {
        $request = Request::create('/v1/chat/completions', 'POST');
        $request->headers->set('Authorization', 'Bearer sk-test-key');

        $this->assertTrue($this->authenticator->supports($request));
    }

    public function testSupportsReturnsTrueWithAnyBearerOnV1Route(): void
    {
        $request = Request::create('/v1/models', 'GET');
        $request->headers->set('Authorization', 'Bearer some-openai-compatible-key');

        $this->assertTrue($this->authenticator->supports($request));
    }

    public function testSupportsReturnsFalseWithNonSkBearerOnApiRoute(): void
    {
        $request = Request::create('/api/v1/messages/stream', 'GET');
        $request->headers->set('Authorization', 'Bearer some-session-token');

        $this->assertFalse($this->authenticator->supports($request));
    }

    public function testSupportsReturnsTrueWithSkBearerOnApiRoute(): void
    {
        $request = Request::create('/api/v1/chats', 'GET');
        $request->headers->set('Authorization', 'Bearer sk_abc123def456');

        $this->assertTrue($this->authenticator->supports($request));
    }

    public function testAuthenticateWithBearerSkPrefixOnApiRoute(): void
    {
        $apiKey = $this->createMock(ApiKey::class);
        $apiKey->method('isActive')->willReturn(true);
        $apiKey->method('getId')->willReturn(1);
        $apiKey->method('getOwnerId')->willReturn(10);
        $apiKey->method('getName')->willReturn('My API Key');
        $apiKey->method('getScopes')->willReturn(['api']);

        $request = Request::create('/api/v1/chats', 'GET');
        $request->headers->set('Authorization', 'Bearer sk_valid_api_key_here');

        $this->apiKeyRepository->method('findActiveByKey')
            ->with('sk_valid_api_key_here')
            ->willReturn($apiKey);
        $this->apiKeyRepository->method('save');

        $passport = $this->authenticator->authenticate($request);

        $this->assertInstanceOf(\Symfony\Component\Security\Http\Authenticator\Passport\Passport::class, $passport);
        $this->assertSame($apiKey, $request->attributes->get('api_key'));
    }

    public function testAuthenticateWithBearerOnV1Route(): void
    {
        $apiKey = $this->createMock(ApiKey::class);
        $apiKey->method('isActive')->willReturn(true);
        $apiKey->method('getId')->willReturn(1);
        $apiKey->method('getOwnerId')->willReturn(10);
        $apiKey->method('getName')->willReturn('Test Key');
        $apiKey->method('getScopes')->willReturn(['openai:*']);

        $request = Request::create('/v1/chat/completions', 'POST');
        $request->headers->set('Authorization', 'Bearer sk-valid-key');

        $this->apiKeyRepository->method('findActiveByKey')
            ->with('sk-valid-key')
            ->willReturn($apiKey);
        $this->apiKeyRepository->method('save');

        $passport = $this->authenticator->authenticate($request);

        $this->assertInstanceOf(\Symfony\Component\Security\Http\Authenticator\Passport\Passport::class, $passport);
        $this->assertSame($apiKey, $request->attributes->get('api_key'));
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

    // ========== X-API-Key takes priority over Bearer ==========

    public function testXApiKeyTakesPriorityOverBearerHeader(): void
    {
        $request = Request::create('/api/v1/chats', 'GET');
        $request->headers->set('X-API-Key', 'sk_header_key');
        $request->headers->set('Authorization', 'Bearer sk_bearer_key');

        $this->apiKeyRepository
            ->expects($this->once())
            ->method('findActiveByKey')
            ->with('sk_header_key');

        try {
            $this->authenticator->authenticate($request);
        } catch (AuthenticationException) {
        }
    }

    // ========== onAuthenticationFailure() ==========

    public function testOnAuthenticationFailureReturns401(): void
    {
        $request = new Request();
        $exception = new AuthenticationException('Invalid API key');

        $response = $this->authenticator->onAuthenticationFailure($request, $exception);

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
        $this->assertSame(401, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertSame('Authentication failed', $content['error']);
        $this->assertSame('Invalid API key', $content['message']);
    }

    // ========== onAuthenticationSuccess() ==========

    public function testOnAuthenticationSuccessReturnsNull(): void
    {
        $request = new Request();
        $token = $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\TokenInterface::class);

        $result = $this->authenticator->onAuthenticationSuccess($request, $token, 'api');

        $this->assertNull($result);
    }

    // ========== Edge Cases ==========

    public function testSupportsReturnsFalseWithBearerNonSkOnApiRoute(): void
    {
        $request = Request::create('/api/v1/chats', 'GET');
        $request->headers->set('Authorization', 'Bearer eyJhbGciOiJIUzI1NiJ9.jwt');

        $this->assertFalse($this->authenticator->supports($request));
    }

    public function testSupportsReturnsTrueWithXApiKeyRegardlessOfRoute(): void
    {
        $request = Request::create('/api/v1/config/models', 'GET');
        $request->headers->set('X-API-Key', 'sk_any_key');

        $this->assertTrue($this->authenticator->supports($request));
    }

    public function testSupportsReturnsTrueWithQueryApiKeyRegardlessOfRoute(): void
    {
        $request = Request::create('/api/v1/chats', 'GET', ['api_key' => 'sk_query_key']);

        $this->assertTrue($this->authenticator->supports($request));
    }
}
