<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp;

use App\Entity\McpServerConfig;
use App\Service\EncryptionService;
use App\Service\Mcp\McpOAuthState;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class McpOAuthStateTest extends TestCase
{
    public function testEntityRoundTripEncryptsTheBlobAndDefaultsToBearer(): void
    {
        $encryption = new EncryptionService('test-secret', new NullLogger());
        $server = new McpServerConfig();
        $server->setUserId(1)->setName('Notion')->setUrl('https://mcp.notion.com/mcp');

        self::assertSame(McpServerConfig::AUTH_MODE_BEARER, $server->getAuthMode());
        self::assertFalse($server->isOAuth());
        self::assertSame(McpOAuthState::STATUS_NOT_CONNECTED, $server->getDecryptedOAuthState($encryption)->status);

        $state = new McpOAuthState(
            resource: 'https://mcp.notion.com/mcp',
            authorizationEndpoint: 'https://mcp.notion.com/authorize',
            tokenEndpoint: 'https://mcp.notion.com/token',
            registrationEndpoint: 'https://mcp.notion.com/register',
            clientId: 'atcl-test',
            scopes: ['default'],
            accessToken: 'secret-access',
            refreshToken: 'secret-refresh',
            expiresAt: 1787327000,
            status: McpOAuthState::STATUS_CONNECTED,
        );
        $server->setAuthMode(McpServerConfig::AUTH_MODE_OAUTH);
        $server->setDecryptedOAuthState($state, $encryption);

        self::assertTrue($server->isOAuth());
        $loaded = $server->getDecryptedOAuthState($encryption);
        self::assertSame('secret-access', $loaded->accessToken);
        self::assertSame('atcl-test', $loaded->clientId);
        self::assertSame(McpOAuthState::STATUS_CONNECTED, $loaded->status);
    }

    public function testFromArrayToleratesMissingKeys(): void
    {
        $state = McpOAuthState::fromArray(['client_id' => 'x']);

        self::assertSame('x', $state->clientId);
        self::assertSame(McpOAuthState::STATUS_NOT_CONNECTED, $state->status);
        self::assertTrue($state->supportsRefresh);
        self::assertFalse($state->hasAccessToken());
    }

    public function testWithoutTokensClearsTheGrant(): void
    {
        $state = new McpOAuthState(accessToken: 'at', refreshToken: 'rt', expiresAt: 9, status: McpOAuthState::STATUS_CONNECTED);
        $cleared = $state->withoutTokens();

        self::assertFalse($cleared->hasAccessToken());
        self::assertSame(McpOAuthState::STATUS_NOT_CONNECTED, $cleared->status);
        self::assertSame($state->clientId, $cleared->clientId);
    }
}
