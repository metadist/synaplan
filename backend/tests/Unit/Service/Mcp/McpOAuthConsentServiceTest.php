<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp;

use App\Entity\McpServerConfig;
use App\Repository\ConfigRepository;
use App\Repository\McpServerConfigRepository;
use App\Service\EncryptionService;
use App\Service\Mcp\McpClientConfig;
use App\Service\Mcp\McpOAuthConsentService;
use App\Service\Mcp\McpOAuthDiscovery;
use App\Service\Mcp\McpOAuthDiscoveryResult;
use App\Service\Mcp\McpOAuthException;
use App\Service\Mcp\McpOAuthRegistration;
use App\Service\Mcp\McpOAuthState;
use App\Service\OAuth\OAuthClient;
use App\Service\OAuthStateService;
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
        $repo = $this->createMock(McpServerConfigRepository::class);
        $repo->expects(self::once())->method('save')->with($server);

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
        $server->setDecryptedOAuthState(new McpOAuthState(
            resource: 'https://mcp.notion.com/mcp',
            authorizationEndpoint: 'https://mcp.notion.com/authorize',
            tokenEndpoint: 'https://mcp.notion.com/token',
            clientId: 'atcl-new',
            scopes: ['default'],
        ), $encryption);

        $repo = $this->createMock(McpServerConfigRepository::class);
        $repo->method('findByIdAndUser')->willReturn($server);
        $repo->expects(self::once())->method('save');

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
        $item->set(['verifier' => 'the-verifier', 'owner_id' => 7, 'server_id' => 42]);
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
    ): McpOAuthConsentService {
        $configRepo = $this->createMock(ConfigRepository::class);
        $configRepo->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $key): string => 'OAUTH_CONNECTORS_ENABLED' === $key
                ? ($oauthEnabled ? '1' : '0')
                : '1'
        );

        $discovery = $this->createMock(McpOAuthDiscovery::class);
        $discovery->method('discover')->willReturn(new McpOAuthDiscoveryResult(
            resource: 'https://mcp.notion.com/mcp',
            authorizationEndpoint: 'https://mcp.notion.com/authorize',
            tokenEndpoint: 'https://mcp.notion.com/token',
            registrationEndpoint: 'https://mcp.notion.com/register',
            scopes: ['default'],
            supportsRefreshGrant: true,
        ));

        $registration = $this->createMock(McpOAuthRegistration::class);
        $registration->method('register')->willReturn(['client_id' => 'atcl-new', 'supports_refresh' => true]);

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
}
