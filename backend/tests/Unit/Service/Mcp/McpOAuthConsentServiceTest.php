<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp;

use App\Entity\McpServerConfig;
use App\Repository\ConfigRepository;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\McpClientConfig;
use App\Service\Mcp\McpOAuthConsentService;
use App\Service\Mcp\McpOAuthDiscovery;
use App\Service\Mcp\McpOAuthDiscoveryResult;
use App\Service\Mcp\McpOAuthException;
use App\Service\Mcp\McpOAuthRegistration;
use App\Service\Mcp\McpOAuthState;
use App\Service\OAuth\OAuthClient;
use App\Service\OAuth\OAuthStateService;
use App\Service\Security\EncryptionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class McpOAuthConsentServiceTest extends TestCase
{
    public function testStartReturnsAuthorizeUrlAndStoresClientId(): void
    {
        $encryption = new EncryptionService('test-secret', new NullLogger());
        $server = $this->server();
        $repo = $this->storingRepository();
        $repo->expects(self::once())->method('storeOAuthStateAt');

        $service = $this->service($encryption, $repo, $this->tokenClient([]));

        $url = $service->start($server, 7);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('https://mcp.notion.com/authorize', strtok($url, '?'));
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame('atcl-new', $server->getDecryptedOAuthState($encryption)->clientId);
        self::assertSame(McpServerConfig::AUTH_MODE_OAUTH, $server->getAuthMode());
    }

    public function testStartIsRejectedWhenFlagIsOff(): void
    {
        $this->expectException(McpOAuthException::class);
        $this->expectExceptionMessageMatches('/disabled by an administrator/');

        $this->service(
            new EncryptionService('test-secret', new NullLogger()),
            $this->createMock(McpServerConfigRepository::class),
            $this->tokenClient([]),
            oauthEnabled: false,
        )->start($this->server(), 7);
    }

    public function testCompleteExchangesTheCodeAndStoresTokens(): void
    {
        $encryption = new EncryptionService('test-secret', new NullLogger());
        $cache = new ArrayAdapter();
        $stateService = new OAuthStateService(new NullLogger(), 'app-secret');
        $server = $this->server();
        $server->setAuthMode(McpServerConfig::AUTH_MODE_OAUTH);
        $server->setDecryptedOAuthState(new McpOAuthState(
            resource: 'https://mcp.notion.com/mcp',
            authorizationEndpoint: 'https://mcp.notion.com/authorize',
            tokenEndpoint: 'https://mcp.notion.com/token',
            clientId: 'atcl-new',
            scopes: ['default'],
        ), $encryption);

        $repo = $this->storingRepository();
        $repo->method('findByIdAndUser')->willReturn($server);
        $repo->expects(self::once())->method('storeOAuthStateAt');

        $oauth = $this->tokenClient([
            new MockResponse((string) json_encode([
                'access_token' => 'at-1',
                'refresh_token' => 'rt-1',
                'expires_in' => 3600,
            ]), ['http_code' => 200]),
        ]);

        $service = $this->service($encryption, $repo, $oauth, cache: $cache, state: $stateService);

        $nonce = bin2hex(random_bytes(16));
        $item = $cache->getItem('mcp_oauth_pkce_'.hash('sha256', $nonce));
        $item->set([
            'verifier' => 'the-verifier',
            'owner_id' => 7,
            'server_id' => 42,
            'server_url' => 'https://mcp.notion.com/mcp',
        ]);
        $cache->save($item);

        $signed = $stateService->generateState(McpOAuthConsentService::PROVIDER, [
            'owner_id' => 7,
            'server_id' => 42,
            'pkce_nonce' => $nonce,
        ]);

        $completed = $service->complete('the-code', $signed);
        $stored = $completed->getDecryptedOAuthState($encryption);

        self::assertSame('at-1', $stored->accessToken);
        self::assertSame('rt-1', $stored->refreshToken);
        self::assertSame(McpOAuthState::STATUS_CONNECTED, $stored->status);
    }

    public function testStartRemembersTheAddressForTheCallback(): void
    {
        $encryption = new EncryptionService('test-secret', new NullLogger());
        $cache = new ArrayAdapter();
        $stateService = new OAuthStateService(new NullLogger(), 'app-secret');
        $server = $this->server();

        $service = $this->service(
            $encryption,
            $this->storingRepository(),
            $this->tokenClient([]),
            cache: $cache,
            state: $stateService,
        );

        $url = $service->start($server, 7);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $payload = $stateService->validateState((string) $query['state'], McpOAuthConsentService::PROVIDER);
        self::assertIsArray($payload);
        $item = $cache->getItem('mcp_oauth_pkce_'.hash('sha256', (string) $payload['pkce_nonce']));
        self::assertTrue($item->isHit());
        self::assertSame('https://mcp.notion.com/mcp', $item->get()['server_url']);
    }

    public function testCompleteIsRefusedWhenTheAddressChangedSinceStart(): void
    {
        $encryption = new EncryptionService('test-secret', new NullLogger());
        $cache = new ArrayAdapter();
        $stateService = new OAuthStateService(new NullLogger(), 'app-secret');
        $server = $this->server();
        $server->setAuthMode(McpServerConfig::AUTH_MODE_OAUTH);
        $server->setUrl('https://mcp.other.example/mcp');

        $repo = $this->createMock(McpServerConfigRepository::class);
        $repo->method('findByIdAndUser')->willReturn($server);
        $repo->expects(self::never())->method('storeOAuthStateAt');

        $service = $this->service($encryption, $repo, $this->tokenClient([]), cache: $cache, state: $stateService);

        $nonce = bin2hex(random_bytes(16));
        $item = $cache->getItem('mcp_oauth_pkce_'.hash('sha256', $nonce));
        $item->set([
            'verifier' => 'the-verifier',
            'owner_id' => 7,
            'server_id' => 42,
            'server_url' => 'https://mcp.notion.com/mcp',
        ]);
        $cache->save($item);

        $signed = $stateService->generateState(McpOAuthConsentService::PROVIDER, [
            'owner_id' => 7,
            'server_id' => 42,
            'pkce_nonce' => $nonce,
        ]);

        $this->expectException(McpOAuthException::class);
        $this->expectExceptionMessageMatches('/address changed/');

        $service->complete('the-code', $signed);
    }

    public function testStartStoresNothingWhenTheAddressChangedMeanwhile(): void
    {
        $this->expectException(McpOAuthException::class);
        $this->expectExceptionMessageMatches('/address changed/');

        $this->service(
            new EncryptionService('test-secret', new NullLogger()),
            $this->storingRepository(stores: false),
            $this->tokenClient([]),
        )->start($this->server(), 7);
    }

    public function testStartIsRefusedWhileMcpCallsAreOff(): void
    {
        $this->expectException(McpOAuthException::class);
        $this->expectExceptionMessageMatches('/turned off/');

        $this->service(
            new EncryptionService('test-secret', new NullLogger()),
            $this->createStub(McpServerConfigRepository::class),
            $this->tokenClient([]),
            clientEnabled: false,
        )->start($this->server(), 7);
    }

    public function testConsentUrlNamesTheServerAsResource(): void
    {
        $url = $this->service(
            new EncryptionService('test-secret', new NullLogger()),
            $this->storingRepository(),
            $this->tokenClient([]),
        )->start($this->server(), 7);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('https://mcp.notion.com/mcp', $query['resource']);
    }

    public function testAnotherAuthorizationServerStartsFromScratch(): void
    {
        $encryption = new EncryptionService('test-secret', new NullLogger());
        $server = $this->server();
        $server->setAuthMode(McpServerConfig::AUTH_MODE_OAUTH);
        $server->setDecryptedOAuthState(new McpOAuthState(
            resource: 'https://mcp.notion.com/mcp',
            authorizationEndpoint: 'https://old-issuer.example/authorize',
            tokenEndpoint: 'https://old-issuer.example/token',
            clientId: 'old-client',
            accessToken: 'old-token',
            refreshToken: 'old-refresh',
            status: McpOAuthState::STATUS_CONNECTED,
        ), $encryption);

        $registration = $this->createMock(McpOAuthRegistration::class);
        $registration->expects(self::once())->method('register')
            ->willReturn(['client_id' => 'atcl-new', 'supports_refresh' => true]);

        $this->service($encryption, $this->storingRepository(), $this->tokenClient([]), registration: $registration)
            ->start($server, 7);

        $state = $server->getDecryptedOAuthState($encryption);
        self::assertSame('atcl-new', $state->clientId);
        self::assertSame('', $state->accessToken);
        self::assertSame('', $state->refreshToken);
        self::assertSame('https://mcp.notion.com/token', $state->tokenEndpoint);
    }

    public function testCompleteIsRefusedWhenTheServerNoLongerUsesSignIn(): void
    {
        [$service, $signed] = $this->pendingCallback($this->server(), $this->createMock(McpServerConfigRepository::class));

        $this->expectException(McpOAuthException::class);
        $this->expectExceptionMessageMatches('/no longer uses sign-in/');

        $service->complete('the-code', $signed);
    }

    public function testCompleteStoresNothingWhenAnEditWinsTheRace(): void
    {
        $encryption = new EncryptionService('test-secret', new NullLogger());

        [$service, $signed] = $this->pendingCallback($this->signInServer($encryption), $this->storingRepository(stores: false), [
            new MockResponse((string) json_encode(['access_token' => 'at-1', 'expires_in' => 3600]), ['http_code' => 200]),
        ], $encryption);

        $this->expectException(McpOAuthException::class);
        $this->expectExceptionMessageMatches('/address changed/');

        $service->complete('the-code', $signed);
    }

    public function testAnswerFromAnotherIssuerIsRefused(): void
    {
        $encryption = new EncryptionService('test-secret', new NullLogger());
        $repo = $this->createMock(McpServerConfigRepository::class);
        $repo->expects(self::never())->method('storeOAuthStateAt');

        [$service, $signed] = $this->pendingCallback($this->signInServer($encryption), $repo, [], $encryption);

        $this->expectException(McpOAuthException::class);
        $this->expectExceptionMessageMatches('/different authorization server/');

        $service->complete('the-code', $signed, 'https://evil.example.com');
    }

    public function testCallbackIssuerIsComparedExactly(): void
    {
        $encryption = new EncryptionService('test-secret', new NullLogger());
        $repo = $this->createMock(McpServerConfigRepository::class);
        $repo->expects(self::never())->method('storeOAuthStateAt');

        [$service, $signed] = $this->pendingCallback($this->signInServer($encryption), $repo, [], $encryption);

        $this->expectException(McpOAuthException::class);
        $this->expectExceptionMessageMatches('/different authorization server/');

        $service->complete('the-code', $signed, 'https://mcp.notion.com/');
    }

    private function signInServer(EncryptionService $encryption): McpServerConfig
    {
        $server = $this->server();
        $server->setAuthMode(McpServerConfig::AUTH_MODE_OAUTH);
        $server->setDecryptedOAuthState(new McpOAuthState(
            resource: 'https://mcp.notion.com/mcp',
            tokenEndpoint: 'https://mcp.notion.com/token',
            clientId: 'atcl-new',
        ), $encryption);

        return $server;
    }

    /**
     * @param list<MockResponse> $tokenResponses
     *
     * @return array{0: McpOAuthConsentService, 1: string}
     */
    private function pendingCallback(
        McpServerConfig $server,
        McpServerConfigRepository&MockObject $repo,
        array $tokenResponses = [],
        ?EncryptionService $encryption = null,
    ): array {
        $cache = new ArrayAdapter();
        $stateService = new OAuthStateService(new NullLogger(), 'app-secret');
        $repo->method('findByIdAndUser')->willReturn($server);

        $service = $this->service(
            $encryption ?? new EncryptionService('test-secret', new NullLogger()),
            $repo,
            $this->tokenClient($tokenResponses),
            cache: $cache,
            state: $stateService,
        );

        $nonce = bin2hex(random_bytes(16));
        $item = $cache->getItem('mcp_oauth_pkce_'.hash('sha256', $nonce));
        $item->set([
            'verifier' => 'v',
            'owner_id' => 7,
            'server_id' => 42,
            'server_url' => $server->getUrl(),
            'issuer' => 'https://mcp.notion.com',
        ]);
        $cache->save($item);

        return [$service, $stateService->generateState(McpOAuthConsentService::PROVIDER, [
            'owner_id' => 7,
            'server_id' => 42,
            'pkce_nonce' => $nonce,
        ])];
    }

    public function testReplayedCallbackIsRejected(): void
    {
        $encryption = new EncryptionService('test-secret', new NullLogger());
        $cache = new ArrayAdapter();
        $stateService = new OAuthStateService(new NullLogger(), 'app-secret');

        $service = $this->service(
            $encryption,
            $this->createMock(McpServerConfigRepository::class),
            $this->tokenClient([]),
            cache: $cache,
            state: $stateService,
        );

        $signed = $stateService->generateState(McpOAuthConsentService::PROVIDER, [
            'owner_id' => 7,
            'server_id' => 42,
            'pkce_nonce' => 'nonce-1',
        ]);

        $this->expectException(McpOAuthException::class);
        $this->expectExceptionMessageMatches('/expired or was already used/');

        $service->complete('the-code', $signed);
    }

    private function service(
        EncryptionService $encryption,
        McpServerConfigRepository $repo,
        OAuthClient $oauth,
        bool $oauthEnabled = true,
        ?ArrayAdapter $cache = null,
        ?OAuthStateService $state = null,
        bool $clientEnabled = true,
        ?McpOAuthRegistration $registration = null,
    ): McpOAuthConsentService {
        $configRepo = $this->createStub(ConfigRepository::class);
        $configRepo->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $key): string => match ($key) {
                'OAUTH_CONNECTORS_ENABLED' => $oauthEnabled ? '1' : '0',
                'CLIENT_ENABLED' => $clientEnabled ? '1' : '0',
                default => '1',
            }
        );

        $discovery = $this->createStub(McpOAuthDiscovery::class);
        $discovery->method('discover')->willReturn(new McpOAuthDiscoveryResult(
            resource: 'https://mcp.notion.com/mcp',
            authorizationEndpoint: 'https://mcp.notion.com/authorize',
            tokenEndpoint: 'https://mcp.notion.com/token',
            registrationEndpoint: 'https://mcp.notion.com/register',
            scopes: ['default'],
            supportsRefreshGrant: true,
            issuer: 'https://mcp.notion.com',
        ));

        if (null === $registration) {
            $registration = $this->createStub(McpOAuthRegistration::class);
            $registration->method('register')->willReturn(['client_id' => 'atcl-new', 'supports_refresh' => true]);
        }

        return new McpOAuthConsentService(
            new McpClientConfig($configRepo),
            $discovery,
            $registration,
            $oauth,
            $state ?? new OAuthStateService(new NullLogger(), 'app-secret'),
            $repo,
            $encryption,
            $cache ?? new ArrayAdapter(),
            new NullLogger(),
            'https://web.synaplan.com',
        );
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function tokenClient(array $responses): OAuthClient
    {
        return new OAuthClient(new MockHttpClient($responses), new NullLogger());
    }

    private function server(): McpServerConfig
    {
        $server = new McpServerConfig();
        $server->setUserId(7)->setName('Notion')->setUrl('https://mcp.notion.com/mcp');
        $ref = new \ReflectionProperty(McpServerConfig::class, 'id');
        $ref->setValue($server, 42);

        return $server;
    }

    /**
     * A repository whose conditional sign-in write behaves like the real one
     * when the row is unchanged: it stores the state on the entity.
     */
    private function storingRepository(bool $stores = true): McpServerConfigRepository&MockObject
    {
        $repo = $this->createMock(McpServerConfigRepository::class);
        $repo->method('storeOAuthStateAt')->willReturnCallback(
            static function (McpServerConfig $server, string $url, string $encrypted, bool $startingSignIn = false) use ($stores): bool {
                if ($stores) {
                    (new \ReflectionProperty(McpServerConfig::class, 'oauth'))->setValue($server, $encrypted);
                    if ($startingSignIn) {
                        (new \ReflectionProperty(McpServerConfig::class, 'authMode'))->setValue($server, McpServerConfig::AUTH_MODE_OAUTH);
                    }
                }

                return $stores;
            }
        );

        return $repo;
    }
}
