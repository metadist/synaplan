<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ApiKey;
use App\Entity\User;
use App\Exception\ApiKeyPlanRequiredException;
use App\Repository\ApiKeyRepository;
use App\Repository\UserRepository;
use App\Security\ApiKeyAuthenticator;
use App\Security\ApiKeyPlanDeniedException;
use App\Service\Billing\BillingService;
use App\Service\Billing\PremiumFeatureGate;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

class ApiKeyAuthenticatorTest extends TestCase
{
    private ApiKeyRepository&MockObject $apiKeyRepository;
    private UserRepository&MockObject $users;
    private LoggerInterface&MockObject $logger;
    private ApiKeyAuthenticator $authenticator;
    private ?User $owner = null;

    protected function setUp(): void
    {
        $this->apiKeyRepository = $this->createMock(ApiKeyRepository::class);
        $this->users = $this->createMock(UserRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->users->method('find')->willReturnCallback(fn () => $this->owner ?? new User());

        $this->authenticator = new ApiKeyAuthenticator(
            $this->apiKeyRepository,
            $this->users,
            $this->logger,
            self::gate(false),
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

    public function testAuthenticateRejectsAPendingPaymentOwner(): void
    {
        $apiKey = $this->createMock(ApiKey::class);
        $apiKey->method('isActive')->willReturn(true);
        $apiKey->method('getId')->willReturn(1);
        $apiKey->method('getOwnerId')->willReturn(10);
        $this->owner = (new User())->setAccountStatus(User::ACCOUNT_STATUS_PENDING_PAYMENT);

        $request = new Request();
        $request->headers->set('X-API-Key', 'pending-owner');
        $this->apiKeyRepository->method('findActiveByKey')->willReturn($apiKey);
        $this->apiKeyRepository->expects($this->never())->method('touchLastUsed');

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
            ->method('touchLastUsed')
            ->with($apiKey);

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

        $this->apiKeyRepository->expects(self::any())->method('findActiveByKey')
            ->with('sk_valid_api_key_here')
            ->willReturn($apiKey);
        $this->apiKeyRepository->method('touchLastUsed');

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

        $this->apiKeyRepository->expects(self::any())->method('findActiveByKey')
            ->with('sk-valid-key')
            ->willReturn($apiKey);
        $this->apiKeyRepository
            ->expects($this->once())
            ->method('touchLastUsed')
            ->with($apiKey);

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
        $this->apiKeyRepository->method('touchLastUsed');

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

    public function testOnAuthenticationFailureOnMessagesUsesAnthropicEnvelope(): void
    {
        $request = Request::create('/v1/messages', 'POST');
        $exception = new AuthenticationException('Invalid or inactive API key');

        $response = $this->authenticator->onAuthenticationFailure($request, $exception);

        $this->assertSame(401, $response->getStatusCode());
        $content = json_decode((string) $response->getContent(), true);
        $this->assertSame('error', $content['type']);
        $this->assertSame('authentication_error', $content['error']['type']);
        $this->assertSame('Invalid or inactive API key', $content['error']['message']);
        $this->assertArrayNotHasKey('success', $content);
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

    public function testSupportsReturnsFalseWithEmptyXApiKeyHeader(): void
    {
        $request = new Request();
        $request->headers->set('X-API-Key', '');

        $this->assertFalse($this->authenticator->supports($request));
    }

    public function testSupportsReturnsFalseWithEmptyApiKeyQueryParam(): void
    {
        $request = new Request(['api_key' => '']);

        $this->assertFalse($this->authenticator->supports($request));
    }

    public function testAuthenticateRejectsAFreeAccountWhenBillingIsOn(): void
    {
        $authenticator = $this->authenticatorWithBilling(true);
        $this->owner = new User();
        $apiKey = $this->activeKey();
        $request = new Request();
        $request->headers->set('X-API-Key', 'free-key');
        $this->apiKeyRepository->method('findActiveByKey')->willReturn($apiKey);
        $this->apiKeyRepository->expects($this->never())->method('touchLastUsed');

        $this->expectException(ApiKeyPlanDeniedException::class);
        $this->expectExceptionMessage(ApiKeyPlanRequiredException::MESSAGE);

        $authenticator->authenticate($request);
    }

    public function testAuthenticateAllowsAPaidAccountWhenBillingIsOn(): void
    {
        $authenticator = $this->authenticatorWithBilling(true);
        $this->owner = (new User())->setUserLevel('PRO');
        $apiKey = $this->activeKey();
        $request = new Request();
        $request->headers->set('X-API-Key', 'paid-key');
        $this->apiKeyRepository->method('findActiveByKey')->willReturn($apiKey);
        $this->apiKeyRepository->expects($this->once())->method('touchLastUsed');

        $passport = $authenticator->authenticate($request);

        $this->assertSame($apiKey, $request->attributes->get('api_key'));
        $this->assertNotNull($passport);
    }

    public function testPlanDenialAnswers403(): void
    {
        $request = Request::create('/v1/chat/completions', 'POST');
        $response = $this->authenticator->onAuthenticationFailure($request, new ApiKeyPlanDeniedException());

        $this->assertSame(403, $response->getStatusCode());
        $content = json_decode((string) $response->getContent(), true);
        $this->assertSame(ApiKeyPlanRequiredException::CODE, $content['code']);
        $this->assertSame(ApiKeyPlanRequiredException::MESSAGE, $content['message']);
    }

    public function testPlanDenialOnMessagesUsesPermissionError(): void
    {
        $request = Request::create('/v1/messages', 'POST');
        $response = $this->authenticator->onAuthenticationFailure($request, new ApiKeyPlanDeniedException());

        $this->assertSame(403, $response->getStatusCode());
        $content = json_decode((string) $response->getContent(), true);
        $this->assertSame('permission_error', $content['error']['type']);
        $this->assertSame(ApiKeyPlanRequiredException::MESSAGE, $content['error']['message']);
    }

    private function authenticatorWithBilling(bool $enabled): ApiKeyAuthenticator
    {
        return new ApiKeyAuthenticator(
            $this->apiKeyRepository,
            $this->users,
            $this->logger,
            self::gate($enabled),
        );
    }

    private static function gate(bool $billingEnabled): PremiumFeatureGate
    {
        return new PremiumFeatureGate(new BillingService(
            $billingEnabled ? 'sk_test_suite' : '',
            $billingEnabled ? 'price_1TestSuitePro' : '',
        ));
    }

    private function activeKey(): ApiKey&MockObject
    {
        $apiKey = $this->createMock(ApiKey::class);
        $apiKey->method('isActive')->willReturn(true);
        $apiKey->method('getId')->willReturn(1);
        $apiKey->method('getOwnerId')->willReturn(10);
        $apiKey->method('getName')->willReturn('Test Key');
        $apiKey->method('getScopes')->willReturn(['*']);

        return $apiKey;
    }
}
