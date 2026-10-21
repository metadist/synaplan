<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp;

use App\Entity\McpServerConfig;
use App\Repository\ConfigRepository;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpClientConfig;
use App\Service\Mcp\NotionLinkedPageReader;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class NotionLinkedPageReaderTest extends TestCase
{
    private const PAGE = 'https://www.notion.so/Roadmap-abc123';

    private McpServerConfigRepository&MockObject $servers;
    private McpClient&MockObject $client;
    private NotionLinkedPageReader $reader;

    protected function setUp(): void
    {
        $this->servers = $this->createMock(McpServerConfigRepository::class);
        $this->client = $this->createMock(McpClient::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getValue')->willReturn('1');
        $this->reader = new NotionLinkedPageReader(
            $this->servers,
            $this->client,
            new McpClientConfig($config),
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

    public function testRefusesTheConnectionWhenThisChatCannotUseMcp(): void
    {
        $this->servers->method('findEnabledByUser')->willReturn([$this->server()]);
        $this->client->expects($this->never())->method('callTool');

        $read = $this->reader->read([self::PAGE], 1, [], null, false);

        self::assertStringContainsString('cannot use your Notion connection', $read->prompt());
    }

    private function server(): McpServerConfig
    {
        $server = new McpServerConfig();
        $server->setUserId(1)->setName('Notion')->setUrl('https://mcp.notion.com/mcp')->setEnabled(true);
        (new \ReflectionProperty($server, 'id'))->setValue($server, 7);

        return $server;
    }
}
