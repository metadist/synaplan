<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mcp;

use App\Entity\McpServerConfig;
use App\Service\Mcp\AtlassianToolArguments;
use App\Service\Mcp\McpClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AtlassianToolArgumentsTest extends TestCase
{
    public function testLeavesOtherServersUntouched(): void
    {
        $client = $this->createMock(McpClient::class);
        $client->expects($this->never())->method('callTool');
        $arguments = new AtlassianToolArguments($client, new NullLogger());

        $completed = $arguments->complete(
            $this->server('https://8.8.8.8/mcp'),
            $this->issueTool(),
            ['issueIdOrKey' => 'IN1-1'],
            'https://deskfiler.atlassian.net/browse/IN1-1',
        );

        self::assertSame(['issueIdOrKey' => 'IN1-1'], $completed);
    }

    public function testFillsTheSiteAndIssueKeyFromABrowseLink(): void
    {
        $client = $this->createMock(McpClient::class);
        $client->expects($this->never())->method('callTool');
        $arguments = new AtlassianToolArguments($client, new NullLogger());

        $completed = $arguments->complete(
            $this->server('https://mcp.atlassian.com/v2/mcp'),
            $this->issueTool(),
            [],
            'Was steht hier? https://deskfiler.atlassian.net/browse/IN1-1?atlOrigin=ignored',
        );

        self::assertSame('https://deskfiler.atlassian.net', $completed['cloudId']);
        self::assertSame('IN1-1', $completed['issueIdOrKey']);
        self::assertSame('full', $completed['view']);
    }

    public function testKeepsACloudIdThePlannerAlreadySent(): void
    {
        $client = $this->createMock(McpClient::class);
        $client->expects($this->never())->method('callTool');
        $arguments = new AtlassianToolArguments($client, new NullLogger());

        $completed = $arguments->complete(
            $this->server('https://mcp.atlassian.com/v2/mcp'),
            $this->issueTool(),
            ['cloudId' => 'https://other.atlassian.net', 'issueIdOrKey' => 'IN1-1', 'view' => 'compact'],
            'https://deskfiler.atlassian.net/browse/IN1-1',
        );

        self::assertSame('https://other.atlassian.net', $completed['cloudId']);
        self::assertSame('compact', $completed['view']);
    }

    public function testUsesTheOnlySiteOnTheAccountWhenTheMessageHasNoLink(): void
    {
        $client = $this->createMock(McpClient::class);
        $client->expects($this->once())->method('callTool')->willReturn([
            'content' => [[
                'type' => 'text',
                'text' => '{"data":{"resources":[{"cloudId":"f13d744b-c75b-4e9a-a4aa-2cfa4ad6782f","url":"https://deskfiler.atlassian.net"}]}}',
            ]],
            'isError' => false,
        ]);
        $arguments = new AtlassianToolArguments($client, new NullLogger());

        $completed = $arguments->complete(
            $this->server('https://mcp.atlassian.com/v2/mcp'),
            $this->searchTool(),
            ['jql' => 'assignee = currentUser() AND resolution = Unresolved'],
            'Schau in meine offenen Tickets',
        );

        self::assertSame('https://deskfiler.atlassian.net', $completed['cloudId']);
        self::assertArrayNotHasKey('view', $completed);
    }

    public function testDoesNotGuessWhenTheAccountHasSeveralSites(): void
    {
        $client = $this->createMock(McpClient::class);
        $client->expects($this->once())->method('callTool')->willReturn([
            'content' => [[
                'type' => 'text',
                'text' => '{"data":{"resources":[{"url":"https://deskfiler.atlassian.net"},{"url":"https://other.atlassian.net"}]}}',
            ]],
            'isError' => false,
        ]);
        $arguments = new AtlassianToolArguments($client, new NullLogger());

        $completed = $arguments->complete(
            $this->server('https://mcp.atlassian.com/v2/mcp'),
            $this->searchTool(),
            ['jql' => 'assignee = currentUser()'],
            'Schau in meine offenen Tickets',
        );

        self::assertArrayNotHasKey('cloudId', $completed);
    }

    /**
     * @return array{name: string, inputSchema: array<string, mixed>}
     */
    private function issueTool(): array
    {
        return [
            'name' => 'getJiraIssue',
            'inputSchema' => [
                'properties' => [
                    'cloudId' => ['type' => 'string'],
                    'issueIdOrKey' => ['type' => 'string'],
                    'view' => ['type' => 'string', 'enum' => ['compact', 'evidence', 'full']],
                ],
                'required' => ['cloudId', 'issueIdOrKey'],
            ],
        ];
    }

    /**
     * @return array{name: string, inputSchema: array<string, mixed>}
     */
    private function searchTool(): array
    {
        return [
            'name' => 'searchJiraIssuesUsingJql',
            'inputSchema' => [
                'properties' => [
                    'cloudId' => ['type' => 'string'],
                    'jql' => ['type' => 'string'],
                ],
                'required' => ['cloudId', 'jql'],
            ],
        ];
    }

    private function server(string $url): McpServerConfig
    {
        $server = new McpServerConfig();
        $server->setUserId(1)->setName('Jira & Confluence')->setUrl($url)->setEnabled(true);
        (new \ReflectionProperty($server, 'id'))->setValue($server, 41);

        return $server;
    }
}
