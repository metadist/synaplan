<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp;

use App\Entity\McpServerConfig;
use App\Repository\ConfigRepository;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpClientConfig;
use App\Service\Mcp\McpOAuthState;
use App\Service\Mcp\Notion\NotionSharing;
use App\Service\Mcp\NotionLinkedPageReader;
use App\Service\Security\EncryptionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class NotionLinkedPageReaderTest extends TestCase
{
    private const PAGE = 'https://www.notion.so/Roadmap-abc123';

    private McpServerConfigRepository&MockObject $servers;
    private McpClient&MockObject $client;
    private EncryptionService $encryption;
    private NotionLinkedPageReader $reader;

    protected function setUp(): void
    {
        $this->servers = $this->createMock(McpServerConfigRepository::class);
        $this->client = $this->createMock(McpClient::class);
        $this->encryption = new EncryptionService('test-secret', new NullLogger());
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getValue')->willReturn('1');
        $this->reader = new NotionLinkedPageReader(
            $this->servers,
            $this->client,
            new McpClientConfig($config),
            $this->encryption,
            new NullLogger(),
        );
    }

    public function testIgnoresLinksThatAreNotNotion(): void
    {
        $this->client->expects($this->never())->method('listTools');

        $read = $this->reader->read(['https://example.com/notion.so/page'], 1);

        self::assertFalse($read->attempted());
    }

    public function testAsksToConnectWhenNotionIsNotConnected(): void
    {
        $this->servers->method('findEnabledByUser')->willReturn([]);
        $this->client->expects($this->never())->method('listTools');

        $read = $this->reader->read([self::PAGE, 'https://example.com/public'], 1);

        self::assertTrue($read->attempted());
        self::assertSame(0, $read->successCount());
        self::assertSame([self::PAGE], $read->handledUrls());
        self::assertStringContainsString('Connect Notion under Apps', $read->prompt());
        self::assertStringContainsString('Nothing was read from the public web', $read->prompt());
    }

    public function testReadsAPastedPageThroughFetch(): void
    {
        $server = $this->server();
        $this->servers->method('findEnabledByUser')->willReturn([$server]);
        $this->client->expects($this->once())->method('listTools')->willReturn([[
            'name' => 'notion-fetch',
            'description' => 'Open a page',
            'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            'annotations' => ['readOnlyHint' => true],
        ]]);
        $this->client->expects($this->once())->method('callTool')->with(
            $server,
            'notion-fetch',
            ['id' => 'https://acme.notion.site/Meeting-notes-99'],
        )->willReturn([
            'content' => [['type' => 'text', 'text' => 'Meeting notes body']],
            'isError' => false,
        ]);

        $read = $this->reader->read(['https://acme.notion.site/Meeting-notes-99'], 1);

        self::assertSame(1, $read->successCount());
        self::assertStringContainsString('Meeting notes body', $read->prompt());
        self::assertStringContainsString('Notion', $read->prompt());
    }

    public function testClaimsAppNotionLinksButNotOtherNotionServices(): void
    {
        $this->servers->method('findEnabledByUser')->willReturn([]);

        $read = $this->reader->read([
            'https://app.notion.com/p/Roadmap-39797ad60eb3806db59eef7653838664',
            'https://api.notion.com/v1/pages/abc',
        ], 1);

        self::assertSame(['https://app.notion.com/p/Roadmap-39797ad60eb3806db59eef7653838664'], $read->handledUrls());
        self::assertStringContainsString('Connect Notion under Apps', $read->prompt());
    }

    public function testRefusesTheConnectionWhenThisChatCannotUseMcp(): void
    {
        $this->servers->method('findEnabledByUser')->willReturn([$this->server()]);
        $this->client->expects($this->never())->method('callTool');

        $read = $this->reader->read([self::PAGE], 1, [], null, false);

        self::assertStringContainsString('cannot use your Notion connection', $read->prompt());
    }

    public function testAsksToConnectWhenTheNotionRowWasDisconnected(): void
    {
        $this->servers->method('findEnabledByUser')->willReturn([
            $this->oauthServer(McpOAuthState::STATUS_NOT_CONNECTED, 4),
        ]);
        $this->client->expects($this->never())->method('listTools');

        $read = $this->reader->read([self::PAGE], 1);

        self::assertStringContainsString('Connect Notion under Apps', $read->prompt());
        self::assertStringContainsString('Nothing was read from the public web', $read->prompt());
    }

    public function testUsesTheSignedInConnectionWhenAStaleRowSortsFirst(): void
    {
        $stale = $this->server(3, 'Old Notion');
        $signedIn = $this->oauthServer(McpOAuthState::STATUS_CONNECTED, 8);
        $this->servers->method('findEnabledByUser')->willReturn([$stale, $signedIn]);
        $this->client->expects($this->once())->method('listTools')->with($signedIn)->willReturn([[
            'name' => 'notion-fetch',
            'description' => 'Open a page',
            'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            'annotations' => ['readOnlyHint' => true],
        ]]);
        $this->client->expects($this->once())->method('callTool')->with(
            $signedIn,
            'notion-fetch',
            ['id' => self::PAGE],
        )->willReturn([
            'content' => [['type' => 'text', 'text' => 'Signed-in page']],
            'isError' => false,
        ]);

        $read = $this->reader->read([self::PAGE], 1);

        self::assertSame(1, $read->successCount());
        self::assertStringContainsString('Connection: Notion', $read->prompt());
        self::assertStringNotContainsString('Old Notion', $read->prompt());
    }

    public function testLeavesAPublishedPageToThePublicReaderWithoutAConnection(): void
    {
        $this->servers->method('findEnabledByUser')->willReturn([]);

        $read = $this->reader->read(['https://acme.notion.site/Handbook-99', self::PAGE], 1);

        self::assertSame([self::PAGE], $read->handledUrls());
    }

    public function testReadsThroughTheSharedConnectionWithoutAnOwnOne(): void
    {
        $shared = $this->oauthServer(McpOAuthState::STATUS_CONNECTED, 12);
        $this->servers->method('findEnabledByUser')->willReturn([]);
        $sharing = $this->createStub(NotionSharing::class);
        $sharing->method('sharedServer')->willReturn($shared);
        $this->client->expects($this->once())->method('listTools')->with($shared)->willReturn([[
            'name' => 'notion-fetch',
            'description' => 'Open a page',
            'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            'annotations' => ['readOnlyHint' => true],
        ]]);
        $this->client->expects($this->once())->method('callTool')->willReturn([
            'content' => [['type' => 'text', 'text' => 'Shared page body']],
            'isError' => false,
        ]);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getValue')->willReturn('1');
        $reader = new NotionLinkedPageReader($this->servers, $this->client, new McpClientConfig($config), $this->encryption, new NullLogger(), $sharing);

        $read = $reader->read([self::PAGE], 1);

        self::assertSame(1, $read->successCount());
        self::assertStringContainsString('Shared page body', $read->prompt());
    }

    private function server(int $id = 7, string $name = 'Notion'): McpServerConfig
    {
        $server = new McpServerConfig();
        $server->setUserId(1)->setName($name)->setUrl('https://mcp.notion.com/mcp')->setEnabled(true);
        (new \ReflectionProperty($server, 'id'))->setValue($server, $id);

        return $server;
    }

    private function oauthServer(string $status, int $id): McpServerConfig
    {
        $server = $this->server($id);
        $server->setAuthMode(McpServerConfig::AUTH_MODE_OAUTH);
        $server->setDecryptedOAuthState(new McpOAuthState(
            resource: 'https://mcp.notion.com/mcp',
            accessToken: McpOAuthState::STATUS_CONNECTED === $status ? 'access' : '',
            status: $status,
        ), $this->encryption);

        return $server;
    }
}
