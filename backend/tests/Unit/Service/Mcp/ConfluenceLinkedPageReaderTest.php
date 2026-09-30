<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp;

use App\Entity\McpServerConfig;
use App\Repository\ConfigRepository;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\ConfluenceLinkedPageReader;
use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpClientConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ConfluenceLinkedPageReaderTest extends TestCase
{
    private const PAGE = 'https://deskfiler.atlassian.net/wiki/spaces/IABG/pages/3101163538/20260930+-+Wednesday';

    private McpServerConfigRepository&MockObject $servers;
    private McpClient&MockObject $client;
    private ConfluenceLinkedPageReader $reader;

    protected function setUp(): void
    {
        $this->servers = $this->createMock(McpServerConfigRepository::class);
        $this->client = $this->createMock(McpClient::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getValue')->willReturn('1');
        $this->reader = new ConfluenceLinkedPageReader(
            $this->servers,
            $this->client,
            new McpClientConfig($config),
            new NullLogger(),
        );
    }

    public function testIgnoresPagesWhenNoAtlassianConnectionExists(): void
    {
        $this->servers->method('findEnabledByUser')->willReturn([]);

        $read = $this->reader->read([self::PAGE], 1);

        self::assertFalse($read->attempted());
    }

    public function testReadsAPastedPageThroughGetConfluenceContent(): void
    {
        $server = $this->server();
        $this->servers->method('findEnabledByUser')->willReturn([$server]);
        $this->client->method('listTools')->willReturn([[
            'name' => 'getConfluenceContent',
            'description' => 'Open a page',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'cloudId' => ['type' => 'string'],
                    'content_id' => ['type' => 'string'],
                    'content_url' => ['type' => 'string'],
                    'content_format' => ['type' => 'string'],
                ],
            ],
            'annotations' => [],
        ]]);
        $this->client->expects($this->once())->method('callTool')->with(
            $server,
            'getConfluenceContent',
            [
                'cloudId' => 'https://deskfiler.atlassian.net',
                'content_url' => self::PAGE,
                'content_id' => '3101163538',
                'content_format' => 'markdown',
            ],
        )->willReturn([
            'content' => [['type' => 'text', 'text' => 'Wednesday notes']],
            'isError' => false,
        ]);

        $read = $this->reader->read([self::PAGE, 'https://example.com/public'], 1);

        self::assertSame(1, $read->successCount());
        self::assertTrue($read->coversAll([self::PAGE]));
        self::assertFalse($read->coversAll([self::PAGE, 'https://example.com/public']));
        self::assertStringContainsString('Wednesday notes', $read->prompt());
        self::assertStringContainsString('Deskfiler', $read->prompt());
    }

    public function testPermissionErrorStaysOnTheConnectionInsteadOfThePublicWeb(): void
    {
        $server = $this->server();
        $this->servers->method('findEnabledByUser')->willReturn([$server]);
        $this->client->method('listTools')->willReturn([[
            'name' => 'getConfluenceContent',
            'description' => '',
            'inputSchema' => ['properties' => ['cloudId' => [], 'content_url' => []]],
            'annotations' => [],
        ]]);
        $this->client->method('callTool')->willReturn([
            'content' => [['type' => 'text', 'text' => '{"error":true,"message":"You don\'t have permission to connect via API token. Please ask your organization admin for access."}']],
            'isError' => true,
        ]);

        $read = $this->reader->read([self::PAGE], 1);

        self::assertSame(0, $read->successCount());
        self::assertTrue($read->coversAll([self::PAGE]));
        self::assertStringContainsString('NOT READ', $read->prompt());
        self::assertStringContainsString('organization admin', $read->prompt());
        self::assertStringContainsString('Do not ask them to paste', $read->prompt());
    }

    public function testPrefersTheSignedInAtlassianConnectionOverAPastedToken(): void
    {
        $token = $this->server();
        $signedIn = $this->server('Jira & Confluence');
        $signedIn->setAuthMode(McpServerConfig::AUTH_MODE_OAUTH);
        $this->servers->method('findEnabledByUser')->willReturn([$token, $signedIn]);
        $this->client->expects($this->once())->method('listTools')->with($signedIn)->willReturn([]);

        $read = $this->reader->read([self::PAGE], 1);

        self::assertStringContainsString('Jira & Confluence', $read->prompt());
    }

    private function server(string $name = 'Deskfiler'): McpServerConfig
    {
        $server = new McpServerConfig();
        $server->setUserId(1)->setName($name)->setUrl('https://mcp.atlassian.com/v2/mcp?tools=all')->setEnabled(true);

        return $server;
    }
}
