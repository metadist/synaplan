<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp;

use App\Entity\McpServerConfig;
use App\Repository\ConfigRepository;
use App\Repository\McpServerConfigRepository;
use App\Service\EncryptionService;
use App\Service\Mcp\McpClientConfig;
use App\Service\Mcp\McpOAuthReauthRequiredException;
use App\Service\Mcp\McpOAuthState;
use App\Service\Mcp\McpOAuthTokenProvider;
use App\Service\OAuth\OAuthClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class McpOAuthTokenProviderTest extends TestCase
{
    public function testReturnsAFreshAccessTokenWithoutRefreshing(): void
    {
        $encryption = new EncryptionService('test-secret', new NullLogger());
        $server = $this->server($encryption, expiresAt: time() + 3600);

        $token = $this->provider($encryption, [])->accessToken($server);

        self::assertSame('fresh-at', $token);
    }

    public function testRefreshesAnExpiredToken(): void
    {
        $encryption = new EncryptionService('test-secret', new NullLogger());
        $server = $this->server($encryption, expiresAt: time() - 10);
        $repo = $this->createMock(McpServerConfigRepository::class);
        $repo->expects(self::once())->method('save');

        $provider = $this->provider($encryption, [
            new MockResponse((string) json_encode([
                'access_token' => 'new-at',
                'refresh_token' => 'new-rt',
                'expires_in' => 3600,
            ]), ['http_code' => 200]),
        ], $repo);

        self::assertSame('new-at', $provider->accessToken($server));
        self::assertSame('new-at', $server->getDecryptedOAuthState($encryption)->accessToken);
    }

    public function testExpiredGrantWithoutRefreshAsksToReconnect(): void
    {
        $encryption = new EncryptionService('test-secret', new NullLogger());
        $server = $this->server($encryption, expiresAt: time() - 10, refreshToken: '');
        $repo = $this->createMock(McpServerConfigRepository::class);
        $repo->expects(self::once())->method('save');

        $this->expectException(McpOAuthReauthRequiredException::class);

        try {
            $this->provider($encryption, [], $repo)->accessToken($server);
        } finally {
            self::assertSame(
                McpOAuthState::STATUS_REAUTH_REQUIRED,
                $server->getDecryptedOAuthState($encryption)->status,
            );
        }
    }

    public function testFlagOffBlocksTokenUse(): void
    {
        $encryption = new EncryptionService('test-secret', new NullLogger());
        $this->expectException(McpOAuthReauthRequiredException::class);
        $this->expectExceptionMessageMatches('/disabled by an administrator/');

        $this->provider($encryption, [], oauthEnabled: false)->accessToken($this->server($encryption, expiresAt: time() + 3600));
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function provider(
        EncryptionService $encryption,
        array $responses,
        ?McpServerConfigRepository $repo = null,
        bool $oauthEnabled = true,
    ): McpOAuthTokenProvider {
        $configRepo = $this->createMock(ConfigRepository::class);
        $configRepo->method('getValue')->willReturn($oauthEnabled ? '1' : '0');

        return new McpOAuthTokenProvider(
            new McpClientConfig($configRepo),
            new OAuthClient(new MockHttpClient($responses), new NullLogger()),
            $encryption,
            $repo ?? $this->createMock(McpServerConfigRepository::class),
            new ArrayAdapter(),
            new NullLogger(),
            'https://web.synaplan.com',
        );
    }

    private function server(EncryptionService $encryption, int $expiresAt, string $refreshToken = 'rt'): McpServerConfig
    {
        $server = new McpServerConfig();
        $server->setUserId(7)->setName('Notion')->setUrl('https://mcp.notion.com/mcp');
        $server->setAuthMode(McpServerConfig::AUTH_MODE_OAUTH);
        $server->setDecryptedOAuthState(new McpOAuthState(
            resource: 'https://mcp.notion.com/mcp',
            authorizationEndpoint: 'https://mcp.notion.com/authorize',
            tokenEndpoint: 'https://mcp.notion.com/token',
            clientId: 'cid',
            accessToken: 'fresh-at',
            refreshToken: $refreshToken,
            expiresAt: $expiresAt,
            status: McpOAuthState::STATUS_CONNECTED,
        ), $encryption);
        $ref = new \ReflectionProperty(McpServerConfig::class, 'id');
        $ref->setValue($server, 9);

        return $server;
    }
}
