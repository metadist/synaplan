<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Controller\KeycloakAuthController;
use App\Entity\ApiKey;
use App\Entity\Token;
use App\Entity\User;
use App\Entity\Widget;
use App\Exception\ApiKeyPlanRequiredException;
use App\Repository\ApiKeyRepository;
use App\Repository\DesktopDeviceRepository;
use App\Repository\ExternalIdentityRepository;
use App\Repository\PlatformInstanceRepository;
use App\Repository\PromptRepository;
use App\Repository\TokenRepository;
use App\Repository\UserRepository;
use App\Repository\WidgetRepository;
use App\Security\AccountAccess;
use App\Security\AccountEntry;
use App\Security\AccountStatusChecker;
use App\Security\ApiKeyAuthenticator;
use App\Security\ApiKeyPlanDeniedException;
use App\Security\OidcBearerAuthenticator;
use App\Security\PendingPaymentSubscriber;
use App\Service\Auth\AuthCookieFactory;
use App\Service\Auth\ImpersonationService;
use App\Service\Auth\NativeAuthHandoffService;
use App\Service\Auth\OAuthLoginResponder;
use App\Service\Auth\OidcAuthorizeParams;
use App\Service\Auth\OidcScopeResolver;
use App\Service\Auth\OidcTokenService;
use App\Service\Auth\TokenService;
use App\Service\Billing\BillingService;
use App\Service\Billing\PremiumFeatureGate;
use App\Service\Desktop\Exception\PairingException;
use App\Service\Desktop\PairingService;
use App\Service\Iam\AuditLogWriter;
use App\Service\Iam\IamConfig;
use App\Service\OAuth\OAuthStateService;
use App\Service\OAuth\Server\AccessTokenValidator;
use App\Service\OidcUserService;
use App\Service\PlatformLink\Exception\PlatformLinkValidationException;
use App\Service\PlatformLink\OutlookConnectService;
use App\Service\PlatformLink\PlatformBearerAttribution;
use App\Service\PlatformLink\RedirectUriPolicy;
use App\Service\RateLimitService;
use App\Service\Widget\WidgetService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

/**
 * Account status against every entry point.
 *
 * Active may use all of them. Pending payment may only sign in (password,
 * refresh, Google, GitHub, Apple, OIDC browser and bearer), be impersonated,
 * and leave impersonation, so checkout stays reachable. Suspended and banned
 * may not enter. The doors that mint a session or spend the platform call
 * {@see AccountAccess} themselves; the firewall user checker and the
 * pending-payment subscriber cover the authenticated HTTP routes.
 */
final class AccountAccessTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: AccountEntry, 2: ?string}>
     */
    public static function matrix(): iterable
    {
        foreach (AccountEntry::cases() as $entry) {
            yield 'active '.$entry->value => [User::ACCOUNT_STATUS_ACTIVE, $entry, null];
            yield 'pending '.$entry->value => [
                User::ACCOUNT_STATUS_PENDING_PAYMENT,
                $entry,
                $entry->allowsPendingPayment() ? null : AccountAccess::PENDING,
            ];
            yield 'suspended '.$entry->value => [User::ACCOUNT_STATUS_SUSPENDED, $entry, AccountAccess::SUSPENDED];
            yield 'banned '.$entry->value => [User::ACCOUNT_STATUS_BANNED, $entry, AccountAccess::SUSPENDED];
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function statuses(): iterable
    {
        yield 'active' => [User::ACCOUNT_STATUS_ACTIVE];
        yield 'pending_payment' => [User::ACCOUNT_STATUS_PENDING_PAYMENT];
        yield 'suspended' => [User::ACCOUNT_STATUS_SUSPENDED];
        yield 'banned' => [User::ACCOUNT_STATUS_BANNED];
    }

    #[DataProvider('matrix')]
    public function testTheStatusDecidesTheDoor(string $status, AccountEntry $entry, ?string $code): void
    {
        $user = self::user($status);

        self::assertSame($code, AccountAccess::code($status, $entry));
        self::assertSame($code, AccountAccess::forUser($user, $entry));
    }

    public function testAnUnknownStatusIsRefused(): void
    {
        self::assertSame(AccountAccess::SUSPENDED, AccountAccess::code('retired', AccountEntry::Password));
        self::assertSame(AccountAccess::SUSPENDED, AccountAccess::code(null, AccountEntry::ApiKey));
        self::assertSame(AccountAccess::SUSPENDED, AccountAccess::forUser(self::user('retired'), AccountEntry::Password));
        self::assertSame(AccountAccess::SUSPENDED, AccountAccess::forUser(self::user('retired'), AccountEntry::ApiKey));
    }

    public function testTheSentencesMatchTheCode(): void
    {
        self::assertSame(
            'This account has been suspended. Please contact support.',
            AccountAccess::message(AccountAccess::SUSPENDED),
        );
        self::assertSame(
            'This account is waiting for payment. Finish checkout to use Synaplan.',
            AccountAccess::message(AccountAccess::PENDING),
        );
    }

    #[DataProvider('statuses')]
    public function testFirewallUserCheckerAndPendingPaymentSubscriberEnforceSignInAndRouteGates(string $status): void
    {
        $user = self::user($status);
        $checker = new AccountStatusChecker();
        $canSignIn = User::ACCOUNT_STATUS_ACTIVE === $status || User::ACCOUNT_STATUS_PENDING_PAYMENT === $status;

        if (!$canSignIn) {
            $this->expectException(CustomUserMessageAccountStatusException::class);
            $this->expectExceptionMessage(AccountAccess::SUSPENDED_MESSAGE);
            $checker->checkPreAuth($user);

            return;
        }

        $checker->checkPreAuth($user);

        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'api', $user->getRoles()));
        $subscriber = new PendingPaymentSubscriber($storage, new NullLogger());
        $kernel = $this->createStub(HttpKernelInterface::class);

        $checkoutEvent = new RequestEvent($kernel, Request::create('/api/v1/subscription/checkout', 'POST'), HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($checkoutEvent);
        self::assertNull($checkoutEvent->getResponse());

        $exitEvent = new RequestEvent($kernel, Request::create('/api/v1/admin/impersonate/exit', 'POST'), HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onKernelRequest($exitEvent);
        self::assertNull($exitEvent->getResponse());

        foreach (['/api/v1/chats', '/mcp', '/v1/chat/completions'] as $protectedPath) {
            $event = new RequestEvent($kernel, Request::create($protectedPath, 'POST'), HttpKernelInterface::MAIN_REQUEST);
            $subscriber->onKernelRequest($event);
            if (User::ACCOUNT_STATUS_ACTIVE === $status) {
                self::assertNull($event->getResponse());
            } else {
                $response = $event->getResponse();
                self::assertNotNull($response);
                self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
                $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
                self::assertSame(AccountAccess::PENDING, $body['code']);
            }
        }
    }

    #[DataProvider('statuses')]
    public function testOAuthResponderAndKeycloakCallbackEnforceSignInRule(string $status): void
    {
        $user = self::user($status);
        $canSignIn = User::ACCOUNT_STATUS_ACTIVE === $status || User::ACCOUNT_STATUS_PENDING_PAYMENT === $status;

        $tokenRepo = $this->createStub(TokenRepository::class);
        $refresh = (new Token())->setUser($user)->setType(TokenService::TYPE_REFRESH)->setToken('rt-1')->setExpires(time() + 3600);
        $tokenRepo->method('createToken')->willReturn($refresh);
        $tokens = new TokenService($tokenRepo, $this->createStub(UserRepository::class), new NullLogger(), new AuthCookieFactory('test', 'https://app.example.com'), 'secret');
        $impersonation = new ImpersonationService($tokens, $this->createStub(UserRepository::class), new NullLogger(), new AuthCookieFactory('test', 'https://app.example.com'), $this->createStub(AuditLogWriter::class), $this->createStub(IamConfig::class));
        $handoff = $this->createStub(NativeAuthHandoffService::class);
        $handoff->method('generate')->willReturn('handoff-1');
        $responder = new OAuthLoginResponder($tokens, $impersonation, $handoff, 'https://app.example.com', 'com.synaplan.app');

        foreach (['google', 'github', 'apple'] as $provider) {
            $web = $responder->success($user, Request::create('/'), $provider, false);
            $native = $responder->success($user, Request::create('/'), $provider, true);
            self::assertInstanceOf(RedirectResponse::class, $web);
            self::assertInstanceOf(RedirectResponse::class, $native);

            if ($canSignIn) {
                self::assertStringContainsString('success=true', $web->getTargetUrl());
                self::assertStringContainsString('handoff=', $native->getTargetUrl());
            } else {
                self::assertStringContainsString('error_code=account_suspended', $web->getTargetUrl());
                self::assertStringContainsString('error_code=account_suspended', $native->getTargetUrl());
            }
        }

        // Keycloak web callback also routes through OAuthLoginResponder without throwing AccountSignInRefused.
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode(['authorization_endpoint' => 'https://idp.example/auth', 'token_endpoint' => 'https://idp.example/token'], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode(['access_token' => 'oidc-at', 'refresh_token' => 'oidc-rt', 'expires_in' => 300], JSON_THROW_ON_ERROR)),
        ]);
        $oidcTokens = $this->createStub(OidcTokenService::class);
        $oidcTokens->method('resolveLoginClaims')->willReturn(['sub' => 'sub-1', 'email' => 'ada@example.com']);
        $oidcUsers = $this->createStub(OidcUserService::class);
        $oidcUsers->method('findOrCreateFromClaims')->willReturn($user);
        $stateService = $this->createStub(OAuthStateService::class);
        $stateService->method('validateState')->willReturn(['pkce_verifier' => 'verifier']);

        $keycloak = new KeycloakAuthController(
            $httpClient,
            $tokens,
            $oidcTokens,
            $oidcUsers,
            $stateService,
            $responder,
            $impersonation,
            new NullLogger(),
            'client-id',
            'client-secret',
            'https://idp.example',
            'openid email profile',
            'https://api.example.com',
            'https://app.example.com',
            new OidcScopeResolver(),
            $this->createStub(OidcAuthorizeParams::class),
        );

        $kcResponse = $keycloak->callback(Request::create('/api/v1/auth/keycloak/callback', 'GET', ['code' => 'auth-code', 'state' => 'state-1']));
        self::assertInstanceOf(RedirectResponse::class, $kcResponse);
        if ($canSignIn) {
            self::assertStringContainsString('success=true', $kcResponse->getTargetUrl());
        } else {
            self::assertStringContainsString('error_code=account_suspended', $kcResponse->getTargetUrl());
        }
    }

    #[DataProvider('statuses')]
    public function testOidcBearerAndImpersonationDoorsFollowSignInRule(string $status): void
    {
        $user = self::user($status);
        $canSignIn = User::ACCOUNT_STATUS_ACTIVE === $status || User::ACCOUNT_STATUS_PENDING_PAYMENT === $status;

        $oidcTokens = $this->createStub(OidcTokenService::class);
        $oidcTokens->method('validateBearerToken')->willReturn(['sub' => 'sub-1', 'email' => 'ada@example.com']);
        $oidcUsers = $this->createStub(OidcUserService::class);
        $oidcUsers->method('findOrCreateFromClaims')->willReturn($user);
        $bearer = new OidcBearerAuthenticator($oidcTokens, $oidcUsers, new NullLogger(), $this->createStub(PlatformBearerAttribution::class), $this->createStub(AccessTokenValidator::class));

        $req = Request::create('/api/v1/chats', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer a.b.c']);
        if ($canSignIn) {
            $passport = $bearer->authenticate($req);
            self::assertSame($user, $passport->getUser());
        } else {
            try {
                $bearer->authenticate($req);
                self::fail('Closed OIDC bearer user must be refused before attribution.');
            } catch (CustomUserMessageAccountStatusException $e) {
                self::assertSame(AccountAccess::SUSPENDED_MESSAGE, $e->getMessage());
            }
        }

        // Impersonation start: active and pending_payment allowed; suspended and banned refused with AccessDeniedException.
        $admin = self::user(User::ACCOUNT_STATUS_ACTIVE, id: 1, level: 'ADMIN');
        $tokenRepo = $this->createStub(TokenRepository::class);
        $tokens = new TokenService($tokenRepo, $this->createStub(UserRepository::class), new NullLogger(), new AuthCookieFactory('test', 'https://app.example.com'), 'secret');
        $impersonation = new ImpersonationService($tokens, $this->createStub(UserRepository::class), new NullLogger(), new AuthCookieFactory('test', 'https://app.example.com'), $this->createStub(AuditLogWriter::class), $this->createStub(IamConfig::class));
        $impRequest = Request::create('/api/v1/admin/impersonate/7', 'POST', cookies: [TokenService::REFRESH_COOKIE => 'admin-rt']);

        if ($canSignIn) {
            $resp = new Response();
            $impersonation->startImpersonation($admin, $user, $impRequest, $resp);
            self::assertNotEmpty($resp->headers->getCookies());
        } else {
            try {
                $impersonation->startImpersonation($admin, $user, $impRequest, new Response());
                self::fail('Impersonating a closed account must throw AccessDeniedException.');
            } catch (AccessDeniedException $e) {
                self::assertSame(AccountAccess::SUSPENDED_MESSAGE, $e->getMessage());
            }
        }
    }

    #[DataProvider('statuses')]
    public function testCredentialAndChannelDoorsRequireAnActiveAccount(string $status): void
    {
        $user = self::user($status);
        $isActive = User::ACCOUNT_STATUS_ACTIVE === $status;

        // 1. PremiumFeatureGate::assertApiKeysAllowed (even in open-source mode without billing)
        $openGate = new PremiumFeatureGate(new BillingService('', ''));
        if ($isActive) {
            $openGate->assertApiKeysAllowed($user);
        } else {
            try {
                $openGate->assertApiKeysAllowed($user);
                self::fail('Non-active user must not mint API keys.');
            } catch (ApiKeyPlanRequiredException) {
            }
        }

        // 2. ApiKeyAuthenticator
        $apiKey = (new ApiKey())->setOwner($user)->setOwnerId(7)->setKey('sk_test')->setName('k')->setStatus('active');
        $keyRepo = $this->createStub(ApiKeyRepository::class);
        $keyRepo->method('findActiveByKey')->willReturn($apiKey);
        $userRepo = $this->createStub(UserRepository::class);
        $userRepo->method('find')->willReturn($user);
        $authenticator = new ApiKeyAuthenticator($keyRepo, $userRepo, $this->createStub(ExternalIdentityRepository::class), new NullLogger(), $openGate);
        $keyReq = Request::create('/v1/models', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer sk_test']);
        if ($isActive) {
            $authenticator->authenticate($keyReq);
            self::assertSame($apiKey, $keyReq->attributes->get('api_key'));
        } elseif (User::ACCOUNT_STATUS_PENDING_PAYMENT === $status) {
            try {
                $authenticator->authenticate($keyReq);
                self::fail('Pending-payment API key must be refused as plan denied.');
            } catch (ApiKeyPlanDeniedException) {
            }
        } else {
            try {
                $authenticator->authenticate($keyReq);
                self::fail('Suspended/banned API key must be refused.');
            } catch (AuthenticationException $e) {
                self::assertNotInstanceOf(ApiKeyPlanDeniedException::class, $e);
            }
        }

        // 3. Desktop PairingService
        $pairing = new PairingService($this->createStub(ApiKeyRepository::class), $this->createStub(DesktopDeviceRepository::class), $userRepo, 'https://app.example.com', $openGate);
        if ($isActive) {
            self::assertSame('ada@example.com', $pairing->pair(7, 'Laptop', [])['account']);
        } else {
            try {
                $pairing->pair(7, 'Laptop', []);
                self::fail('Non-active user must not pair a computer.');
            } catch (PairingException) {
            }
        }

        // 4. OutlookConnectService (must refuse before auditing redirectUri)
        if ($isActive) {
            $audit = $this->createStub(AuditLogWriter::class);
        } else {
            $audit = $this->createMock(AuditLogWriter::class);
            $audit->expects(self::never())->method('record');
        }
        $outlook = new OutlookConnectService($this->createStub(ApiKeyRepository::class), $this->createStub(PlatformInstanceRepository::class), $this->createStub(RedirectUriPolicy::class), $audit, $openGate);
        if ($isActive) {
            self::assertSame('ada@example.com', $outlook->connect($user, 'nonce', '', '', 'https://app.example.com', 'Mac OS X', '127.0.0.1')['payload']['email']);
        } else {
            try {
                $outlook->connect($user, 'nonce', 'https://untrusted.example/relay', '', 'https://app.example.com', 'Mac OS X', '127.0.0.1');
                self::fail('Non-active user must not connect Outlook add-in.');
            } catch (PlatformLinkValidationException) {
            }
        }

        // 5. WidgetService::isWidgetActive
        $widget = (new Widget())->setOwner($user)->setOwnerId(7)->setStatus('active');
        $limits = $this->createStub(RateLimitService::class);
        $limits->method('checkLimit')->willReturn(['allowed' => true]);
        $widgetService = new WidgetService($this->createStub(EntityManagerInterface::class), $this->createStub(WidgetRepository::class), $this->createStub(PromptRepository::class), $limits, new NullLogger());
        self::assertSame($isActive, $widgetService->isWidgetActive($widget));
    }

    private static function user(string $status, int $id = 7, string $level = 'PRO'): User
    {
        $user = (new User())
            ->setMail('ada@example.com')
            ->setUserLevel($level)
            ->setEmailVerified(true)
            ->setAccountStatus($status);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
