<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Execution;

use App\Entity\McpServerConfig;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\McpServerConfigRepository;
use App\Repository\UserRepository;
use App\Service\Mcp\McpClient;
use App\Service\Mcp\McpClientException;
use App\Service\Mcp\McpOAuthState;
use App\Service\Mcp\McpToolRegistry;
use App\Service\Mcp\Notion\NotionSharing;
use App\Service\Security\EncryptionService;
use App\Service\Tool\Execution\McpToolExecutor;
use App\Service\Tool\Execution\ToolChannel;
use App\Service\Tool\ToolSource;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

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

    public function testAReadOnlyFetchUsesTheSharedNotionConnection(): void
    {
        $shared = $this->sharedNotion();
        $client = $this->createMock(McpClient::class);
        $client->expects(self::once())->method('callTool')->with($shared, 'notion-fetch', [])->willReturn([
            'content' => [['type' => 'text', 'text' => 'page']],
            'isError' => false,
        ]);
        $executor = new McpToolExecutor($client, $this->servers(null), $this->catalog($shared));
        $result = $executor->execute(
            $this->descriptor(ToolSource::Mcp, 'notion-fetch', ['serverId' => 12, 'tool' => 'notion-fetch']),
            $this->invocation(ToolChannel::MCP_FETCH, 'notion-fetch'),
        );

        self::assertSame('page', $result->text);
        self::assertFalse($result->isError);
    }

    public function testAWriteChannelDoesNotUseTheSharedNotionConnection(): void
    {
        $client = $this->createMock(McpClient::class);
        $client->expects(self::never())->method('callTool');
        $executor = new McpToolExecutor($client, $this->servers(null), $this->catalog($this->sharedNotion()));
        $result = $executor->execute(
            $this->descriptor(ToolSource::Mcp, 'notion-update-page', ['serverId' => 12, 'tool' => 'notion-update-page']),
            $this->invocation(ToolChannel::MCP_ACTION, 'notion-update-page'),
        );

        self::assertSame('MCP server is not available.', $result->text);
        self::assertTrue($result->isError);
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

    private function catalog(McpServerConfig $shared): McpToolRegistry
    {
        $servers = $this->createStub(McpServerConfigRepository::class);
        $servers->method('findEnabledByUser')->willReturn([]);
        $config = $this->createStub(ConfigRepository::class);
        $config->method('getValue')->willReturn('12');
        $sharingServers = $this->createStub(McpServerConfigRepository::class);
        $sharingServers->method('find')->willReturn($shared);
        $owner = $this->createStub(User::class);
        $owner->method('isAdmin')->willReturn(true);
        $owner->method('getId')->willReturn(4);
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($owner);

        return new McpToolRegistry(
            $this->createStub(McpClient::class),
            $servers,
            new ArrayAdapter(),
            new NullLogger(),
            notionSharing: new NotionSharing($config, $sharingServers, $users, new EncryptionService('test-secret', new NullLogger())),
        );
    }

    private function sharedNotion(): McpServerConfig
    {
        $server = $this->createStub(McpServerConfig::class);
        $server->method('getId')->willReturn(12);
        $server->method('getUserId')->willReturn(4);
        $server->method('isEnabled')->willReturn(true);
        $server->method('isOAuth')->willReturn(true);
        $server->method('getUrl')->willReturn('https://mcp.notion.com/mcp');
        $server->method('getDecryptedOAuthState')->willReturn(new McpOAuthState(
            status: McpOAuthState::STATUS_CONNECTED,
            accessToken: 'at',
        ));

        return $server;
    }
}
