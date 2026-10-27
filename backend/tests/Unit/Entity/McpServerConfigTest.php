<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\McpServerConfig;
use App\Service\Mcp\McpOAuthState;
use App\Service\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class McpServerConfigTest extends TestCase
{
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        $this->encryption = new EncryptionService('test-secret', new NullLogger());
    }

    public function testChangingTheHostDropsTheSavedKey(): void
    {
        $server = $this->bearer('https://crm.example.com/mcp');

        $server->setUrl('https://other.example.net/mcp');

        self::assertFalse($server->hasAuthToken());
    }

    public function testChangingOnlyThePathKeepsTheSavedKey(): void
    {
        $server = $this->bearer('https://crm.example.com/mcp');

        $server->setUrl('https://crm.example.com/v2/mcp');

        self::assertSame('secret', $server->getDecryptedAuthToken($this->encryption));
    }

    public function testChangingThePortOrSchemeDropsTheSavedKey(): void
    {
        $port = $this->bearer('https://crm.example.com/mcp');
        $port->setUrl('https://crm.example.com:8443/mcp');
        self::assertFalse($port->hasAuthToken());

        $scheme = $this->bearer('https://crm.example.com/mcp');
        $scheme->setUrl('http://crm.example.com/mcp');
        self::assertFalse($scheme->hasAuthToken());
    }

    public function testSameAddressWrittenDifferentlyKeepsEverything(): void
    {
        $server = $this->oauth('https://mcp.example.com/mcp');

        $server->setUrl('https://MCP.example.com:443/mcp/');

        self::assertSame('at-1', $server->getDecryptedOAuthState($this->encryption)->accessToken);
    }

    public function testAChangedQueryIsAnotherAddress(): void
    {
        $server = $this->oauth('https://mcp.example.com/mcp?tenant=alpha');

        $server->setUrl('https://mcp.example.com/mcp?tenant=alpha/');

        self::assertSame('', $server->getDecryptedOAuthState($this->encryption)->accessToken);
    }

    public function testAnyAddressChangeDropsTheSignIn(): void
    {
        $server = $this->oauth('https://mcp.example.com/mcp');

        $server->setUrl('https://mcp.example.com/other');

        $state = $server->getDecryptedOAuthState($this->encryption);
        self::assertSame('', $state->accessToken);
        self::assertSame('', $state->clientId);
    }

    public function testLeavingSignInDropsIt(): void
    {
        $server = $this->oauth('https://mcp.example.com/mcp');

        $server->setAuthMode(McpServerConfig::AUTH_MODE_BEARER);

        self::assertSame('', $server->getDecryptedOAuthState($this->encryption)->accessToken);
    }

    public function testLeavingAccessKeyDropsIt(): void
    {
        $server = $this->bearer('https://crm.example.com/mcp');

        $server->setAuthMode(McpServerConfig::AUTH_MODE_NONE);

        self::assertFalse($server->hasAuthToken());
    }

    public function testSettingTheSameModeKeepsTheCredential(): void
    {
        $server = $this->bearer('https://crm.example.com/mcp');

        $server->setAuthMode(McpServerConfig::AUTH_MODE_BEARER);

        self::assertTrue($server->hasAuthToken());
    }

    private function bearer(string $url): McpServerConfig
    {
        $server = (new McpServerConfig())->setUrl($url)->setAuthHeader('Authorization');
        $server->setDecryptedAuthToken('secret', $this->encryption);

        return $server;
    }

    private function oauth(string $url): McpServerConfig
    {
        $server = (new McpServerConfig())->setUrl($url)->setAuthMode(McpServerConfig::AUTH_MODE_OAUTH);
        $server->setDecryptedOAuthState(new McpOAuthState(
            resource: $url,
            authorizationEndpoint: 'https://mcp.example.com/authorize',
            tokenEndpoint: 'https://mcp.example.com/token',
            clientId: 'client-1',
            accessToken: 'at-1',
            refreshToken: 'rt-1',
            status: McpOAuthState::STATUS_CONNECTED,
        ), $this->encryption);

        return $server;
    }
}
