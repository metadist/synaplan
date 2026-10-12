<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp;

use App\Entity\McpServerConfig;
use App\Repository\ConfigRepository;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\AtlassianToolArguments;
use App\Service\Mcp\JiraLinkedIssueReader;
use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpClientConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class JiraLinkedIssueReaderTest extends TestCase
{
    private const ISSUE = 'https://deskfiler.atlassian.net/browse/IN1-1?atlOrigin=ignored';

    private McpServerConfigRepository&MockObject $servers;
    private McpClient&MockObject $client;
    private JiraLinkedIssueReader $reader;

    protected function setUp(): void
    {
        $this->servers = $this->createMock(McpServerConfigRepository::class);
        $this->client = $this->createMock(McpClient::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('getValue')->willReturn('1');
        $this->reader = new JiraLinkedIssueReader(
            $this->servers,
            $this->client,
            new McpClientConfig($config),
            new AtlassianToolArguments($this->client, new NullLogger()),
            new NullLogger(),
        );
    }

    public function testIgnoresLinksThatAreNotJiraIssues(): void
    {
        $this->client->expects($this->never())->method('listTools');

        $read = $this->reader->read(['https://example.com/browse/IN1-1'], 1);

        self::assertFalse($read->attempted());
    }

    public function testReadsAPastedIssueThroughGetJiraIssue(): void
    {
        $server = $this->server();
        $this->servers->method('findEnabledByUser')->willReturn([$server]);
        $this->client->expects($this->once())->method('listTools')->willReturn([[
            'name' => 'getJiraIssue',
            'description' => 'Open an issue',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'cloudId' => ['type' => 'string'],
                    'issueIdOrKey' => ['type' => 'string'],
                    'view' => ['type' => 'string', 'enum' => ['compact', 'evidence', 'full']],
                ],
                'required' => ['cloudId', 'issueIdOrKey'],
            ],
            'annotations' => ['readOnlyHint' => true],
        ]]);
        $this->client->expects($this->once())->method('callTool')->with(
            $server,
            'getJiraIssue',
            [
                'issueIdOrKey' => 'IN1-1',
                'cloudId' => 'https://deskfiler.atlassian.net',
                'view' => 'full',
            ],
        )->willReturn([
            'content' => [['type' => 'text', 'text' => '{"data":{"key":"IN1-1","fields":{"summary":"Test ticket"}}}']],
            'isError' => false,
        ]);

        $read = $this->reader->read([self::ISSUE, 'https://example.com/public'], 1);

        self::assertSame(1, $read->successCount());
        self::assertTrue($read->coversAll([self::ISSUE]));
        self::assertFalse($read->coversAll([self::ISSUE, 'https://example.com/public']));
        self::assertStringContainsString('Test ticket', $read->prompt());
        self::assertStringContainsString('Jira & Confluence', $read->prompt());
    }

    private function server(): McpServerConfig
    {
        $server = new McpServerConfig();
        $server->setUserId(1)->setName('Jira & Confluence')->setUrl('https://mcp.atlassian.com/v2/mcp')->setEnabled(true);
        (new \ReflectionProperty($server, 'id'))->setValue($server, 41);

        return $server;
    }
}
