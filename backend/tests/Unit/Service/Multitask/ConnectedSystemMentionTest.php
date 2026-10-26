<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask;

use App\Entity\McpServerConfig;
use App\Repository\McpServerConfigRepository;
use App\Service\Mcp\McpClientConfig;
use App\Service\Multitask\ConnectedSystemMention;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Prompt\PromptService;
use App\Service\Runtime\RuntimeProfile;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ConnectedSystemMentionTest extends TestCase
{
    public function testANamedConnectionForcesPlanning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');
        $mention = $this->mention([3 => 'Notion'], logger: $logger);

        self::assertTrue($mention->mentioned(1, 'Ich habe in meinem Notion irgendwo eine Seite mit Synaplan', ['topic' => 'general']));
        self::assertSame(['Notion'], $mention->names(1));
    }

    public function testTheNameMustBeAWholeWord(): void
    {
        $mention = $this->mention([3 => 'Notion']);

        self::assertFalse($mention->mentioned(1, 'That sounds notional, not a page', ['topic' => 'general']));
        self::assertFalse($mention->mentioned(1, 'No connection is named here', ['topic' => 'general']));
    }

    public function testMatchingIgnoresCaseAndQuotesRegexCharacters(): void
    {
        $mention = $this->mention([3 => 'Notion (work)']);

        self::assertTrue($mention->mentioned(1, 'Check NOTION (work) for the page', ['topic' => 'general']));
    }

    public function testADisabledServerIsNotListed(): void
    {
        $servers = $this->createMock(McpServerConfigRepository::class);
        $servers->expects(self::exactly(2))->method('findEnabledByUser')->with(1)->willReturn([]);

        $mention = $this->mention([], $servers);

        self::assertFalse($mention->mentioned(1, 'Look in Notion', ['topic' => 'general']));
        self::assertSame([], $mention->names(1));
    }

    public function testTheMcpFetchFlagGatesTheLookup(): void
    {
        $servers = $this->createMock(McpServerConfigRepository::class);
        $servers->expects(self::never())->method('findEnabledByUser');

        $mention = $this->mention([3 => 'Notion'], $servers, fetchEnabled: false);

        self::assertFalse($mention->mentioned(1, 'Look in Notion', ['topic' => 'general']));
    }

    public function testTheClientFlagGatesTheLookup(): void
    {
        $servers = $this->createMock(McpServerConfigRepository::class);
        $servers->expects(self::never())->method('findEnabledByUser');

        $mention = $this->mention([3 => 'Notion'], $servers, clientEnabled: false);

        self::assertFalse($mention->mentioned(1, 'Look in Notion', ['topic' => 'general']));
    }

    public function testATopicThatDisallowsMcpDoesNotForcePlanning(): void
    {
        $mention = $this->mention([3 => 'Notion'], metadata: ['tool_mcp' => false]);

        self::assertFalse($mention->mentioned(1, 'Look in Notion', ['topic' => 'general']));
    }

    public function testAStringToolFlagStillAllowsPlanning(): void
    {
        $mention = $this->mention([3 => 'Notion'], metadata: ['tool_mcp' => '1']);

        self::assertTrue($mention->mentioned(1, 'Look in Notion', ['topic' => 'general']));
    }

    public function testAnAllowlistExcludesOtherServers(): void
    {
        $mention = $this->mention([3 => 'Notion'], metadata: ['tool_mcp' => true, 'mcp_servers' => '9']);

        self::assertFalse($mention->mentioned(1, 'Look in Notion', ['topic' => 'general']));
    }

    public function testAnAllowlistKeepsTheNamedServer(): void
    {
        $mention = $this->mention([3 => 'Notion'], metadata: ['tool_mcp' => true, 'mcp_servers' => '3, 9']);

        self::assertTrue($mention->mentioned(1, 'Look in Notion', ['topic' => 'general']));
    }

    public function testARuntimeProfileCanTurnMcpOff(): void
    {
        $prompts = $this->createMock(PromptService::class);
        $prompts->expects(self::never())->method('getPromptWithMetadata');
        $mention = $this->mention([3 => 'Notion'], prompts: $prompts);

        $profile = new RuntimeProfile(null, 'general', null, [], [], ['tool_mcp' => false], null, null, []);

        self::assertFalse($mention->mentioned(1, 'Look in Notion', ['topic' => 'general', 'runtime_profile' => $profile]));
    }

    public function testAnonymousUsersHaveNoConnections(): void
    {
        $servers = $this->createMock(McpServerConfigRepository::class);
        $servers->expects(self::never())->method('findEnabledByUser');

        self::assertFalse($this->mention([3 => 'Notion'], $servers)->mentioned(null, 'Look in Notion', ['topic' => 'general']));
    }

    /**
     * @param array<int, string> $serversById
     */
    private function mention(
        array $serversById,
        ?McpServerConfigRepository $servers = null,
        bool $clientEnabled = true,
        bool $fetchEnabled = true,
        array $metadata = ['tool_mcp' => true],
        ?PromptService $prompts = null,
        ?LoggerInterface $logger = null,
    ): ConnectedSystemMention {
        if (null === $servers) {
            $servers = $this->createStub(McpServerConfigRepository::class);
            $rows = [];
            foreach ($serversById as $id => $name) {
                $rows[] = $this->server($id, $name);
            }
            $servers->method('findEnabledByUser')->willReturn($rows);
        }

        $client = $this->createStub(McpClientConfig::class);
        $client->method('isClientEnabled')->willReturn($clientEnabled);

        $routing = $this->createStub(MultitaskRoutingConfig::class);
        $routing->method('isFeatureEnabled')->willReturn($fetchEnabled);

        if (null === $prompts) {
            $prompts = $this->createStub(PromptService::class);
            $prompts->method('getPromptWithMetadata')->willReturn(['metadata' => $metadata]);
        }

        return new ConnectedSystemMention($servers, $client, $routing, $prompts, $logger ?? $this->createStub(LoggerInterface::class));
    }

    private function server(int $id, string $name): McpServerConfig
    {
        $server = (new McpServerConfig())->setUserId(1)->setName($name)->setUrl('https://mcp.notion.com/mcp')->setEnabled(true);
        $idProperty = new \ReflectionProperty(McpServerConfig::class, 'id');
        $idProperty->setValue($server, $id);

        return $server;
    }
}
