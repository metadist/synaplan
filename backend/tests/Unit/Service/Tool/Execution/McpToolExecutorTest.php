<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Execution;

use App\Entity\McpServerConfig;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpClientException;
use App\Service\Tool\Execution\McpToolExecutor;
use App\Service\Tool\Execution\ToolChannel;
use App\Service\Tool\ToolSource;
use PHPUnit\Framework\TestCase;

final class McpToolExecutorTest extends TestCase
{
    use ToolExecutionExamples;

    public function testResumeNamesTheMissingServer(): void
    {
        $executor = new McpToolExecutor($this->createStub(McpClient::class), $this->servers(null));
        $result = $executor->execute(
            $this->descriptor(ToolSource::Mcp, 'create_issue', ['serverId' => 4, 'tool' => 'create_issue']),
            $this->invocation(ToolChannel::RESUME, 'create_issue'),
        );

        self::assertTrue($result->isError);
        self::assertSame('MCP server 4 for tool create_issue is not available', $result->text);
    }

    public function testOtherChannelsShareTheShortUnavailableSentence(): void
    {
        $executor = new McpToolExecutor($this->createStub(McpClient::class), $this->servers(null));
        $result = $executor->execute(
            $this->descriptor(ToolSource::Mcp, 'search', ['serverId' => 1, 'tool' => 'search']),
            $this->invocation(ToolChannel::MESSAGES, 'search'),
        );

        self::assertSame('MCP server is not available.', $result->text);
    }

    public function testATransportFailureKeepsTheClientMessage(): void
    {
        $client = $this->createMock(McpClient::class);
        $client->expects(self::once())->method('callTool')->willThrowException(new McpClientException('timed out', httpStatus: 504));
        $executor = new McpToolExecutor($client, $this->servers($this->server()));
        $result = $executor->execute(
            $this->descriptor(ToolSource::Mcp, 'search', ['serverId' => 1, 'tool' => 'search']),
            $this->invocation(ToolChannel::MCP_FETCH, 'search'),
        );

        self::assertTrue($result->isError);
        self::assertSame('timed out', $result->text);
        self::assertSame(504, $result->details['httpStatus']);
        self::assertTrue($result->details['transport']);
    }

    public function testResumeTurnsAToolErrorIntoTheOldSentence(): void
    {
        $client = $this->createStub(McpClient::class);
        $client->method('callTool')->willReturn(['content' => [['type' => 'text', 'text' => 'nope']], 'isError' => true]);
        $executor = new McpToolExecutor($client, $this->servers($this->server()));
        $result = $executor->execute(
            $this->descriptor(ToolSource::Mcp, 'create_issue', ['serverId' => 1, 'tool' => 'create_issue']),
            $this->invocation(ToolChannel::RESUME, 'create_issue'),
        );

        self::assertSame('MCP tool create_issue reported an error: nope', $result->text);
    }

    public function testMessagesJoinTextBlocksTheWayTheGatewayDid(): void
    {
        $client = $this->createStub(McpClient::class);
        $client->method('callTool')->willReturn([
            'content' => [
                ['type' => 'text', 'text' => 'one'],
                ['type' => 'text', 'text' => 'two'],
            ],
            'isError' => false,
        ]);
        $executor = new McpToolExecutor($client, $this->servers($this->server()));
        $result = $executor->execute(
            $this->descriptor(ToolSource::Mcp, 'search', ['serverId' => 1, 'tool' => 'search']),
            $this->invocation(ToolChannel::MESSAGES, 'search'),
        );

        self::assertSame("one\n\ntwo", $result->text);
        self::assertSame('mcp:1:search', $result->details['ref']);
    }

    private function servers(?McpServerConfig $server): McpServerConfigRepository
    {
        $servers = $this->createStub(McpServerConfigRepository::class);
        $servers->method('findByIdAndUser')->willReturn($server);

        return $servers;
    }

    private function server(): McpServerConfig
    {
        $server = $this->createStub(McpServerConfig::class);
        $server->method('isEnabled')->willReturn(true);

        return $server;
    }
}
