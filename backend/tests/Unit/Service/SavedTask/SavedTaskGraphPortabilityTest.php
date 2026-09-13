<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SavedTask;

use App\Entity\McpServerConfig;
use App\Entity\Prompt;
use App\Repository\McpServerConfigRepository;
use App\Repository\PromptRepository;
use App\Service\SavedTask\Graph\SavedTaskGraphPortability;
use PHPUnit\Framework\TestCase;

final class SavedTaskGraphPortabilityTest extends TestCase
{
    public function testExportRewritesPromptAndMcpAndDropsSecrets(): void
    {
        $prompt = new Prompt();
        $prompt->setTopic('support');
        (new \ReflectionProperty(Prompt::class, 'id'))->setValue($prompt, 12);

        $server = $this->createMock(McpServerConfig::class);
        $server->method('getName')->willReturn('Helpdesk');

        $prompts = $this->createMock(PromptRepository::class);
        $prompts->expects(self::any())->method('find')->with(12)->willReturn($prompt);

        $mcp = $this->createMock(McpServerConfigRepository::class);
        $mcp->expects(self::any())->method('findByIdAndUser')->with(9, 4)->willReturn($server);

        $graph = (new SavedTaskGraphPortability($prompts, $mcp))->exportGraph([
            'nodes' => [
                ['id' => 'n1', 'capability' => 'chat', 'params' => ['prompt_id' => '12']],
                ['id' => 'n2', 'capability' => 'tool_call', 'params' => ['tool' => 'mcp:9:create']],
                ['id' => 'n3', 'capability' => 'outbound_webhook', 'params' => ['url' => 'https://ex', 'secret' => 's']],
            ],
        ], 4);

        self::assertSame('support', $graph['nodes'][0]['params']['prompt_topic'] ?? null);
        self::assertSame('support', $graph['nodes'][0]['params']['topic_id'] ?? null);
        self::assertArrayNotHasKey('prompt_id', $graph['nodes'][0]['params']);
        self::assertSame('mcp:Helpdesk:create', $graph['nodes'][1]['params']['tool'] ?? null);
        self::assertArrayNotHasKey('secret', $graph['nodes'][2]['params']);
    }

    public function testImportResolvesMcpNameAndListsMissingServer(): void
    {
        $prompts = $this->createStub(PromptRepository::class);
        $mcp = $this->createMock(McpServerConfigRepository::class);
        $mcp->method('findByUserAndName')->willReturn(null);

        $imported = (new SavedTaskGraphPortability($prompts, $mcp))->importGraph([
            'nodes' => [
                ['id' => 'n1', 'capability' => 'tool_call', 'params' => ['tool' => 'mcp:Helpdesk:create']],
            ],
        ], 4);

        self::assertSame('mcp:Helpdesk:create', $imported['graph']['nodes'][0]['params']['tool'] ?? null);
        self::assertSame('needsConnection', $imported['checklist'][0]['code'] ?? null);
    }

    public function testImportKeepsTopicIdAndPromptIdWhenAssistantExists(): void
    {
        $prompt = new Prompt();
        $prompt->setTopic('support');
        (new \ReflectionProperty(Prompt::class, 'id'))->setValue($prompt, 44);

        $prompts = $this->createMock(PromptRepository::class);
        $prompts->expects($this->once())
            ->method('findByTopicAndUser')
            ->with('support', 4)
            ->willReturn($prompt);

        $imported = (new SavedTaskGraphPortability(
            $prompts,
            $this->createStub(McpServerConfigRepository::class),
        ))->importGraph([
            'nodes' => [
                ['id' => 'n1', 'capability' => 'chat', 'params' => ['prompt_topic' => 'support']],
            ],
        ], 4);

        self::assertSame('support', $imported['graph']['nodes'][0]['params']['topic_id'] ?? null);
        self::assertSame('44', $imported['graph']['nodes'][0]['params']['prompt_id'] ?? null);
        self::assertSame([], $imported['checklist']);
    }

    public function testUnknownItemKeys(): void
    {
        $port = new SavedTaskGraphPortability(
            $this->createStub(PromptRepository::class),
            $this->createStub(McpServerConfigRepository::class),
        );

        self::assertSame(['token'], $port->unknownItemKeys(['name' => 'A', 'token' => 'nope']));
    }

    public function testExportTriggerConfigDropsMailboxId(): void
    {
        $port = new SavedTaskGraphPortability(
            $this->createStub(PromptRepository::class),
            $this->createStub(McpServerConfigRepository::class),
        );

        self::assertSame(
            ['folder' => 'INBOX'],
            $port->exportTriggerConfig(['accountId' => 77, 'folder' => 'INBOX']),
        );
    }

    public function testExportDeletedMcpDoesNotKeepNumericId(): void
    {
        $mcp = $this->createMock(McpServerConfigRepository::class);
        $mcp->expects(self::once())->method('findByIdAndUser')->with(9, 4)->willReturn(null);

        $graph = (new SavedTaskGraphPortability(
            $this->createStub(PromptRepository::class),
            $mcp,
        ))->exportGraph([
            'nodes' => [
                ['id' => 'n1', 'capability' => 'tool_call', 'params' => ['tool' => 'mcp:9:create']],
            ],
        ], 4);

        self::assertSame(
            'mcp:'.SavedTaskGraphPortability::MISSING_MCP_SERVER.':create',
            $graph['nodes'][0]['params']['tool'] ?? null,
        );
    }

    public function testImportKeepsNamedMcpWhenServerIsDisabled(): void
    {
        $server = $this->createMock(McpServerConfig::class);
        $server->method('isEnabled')->willReturn(false);

        $mcp = $this->createMock(McpServerConfigRepository::class);
        $mcp->expects(self::any())->method('findByUserAndName')->with(4, 'Helpdesk')->willReturn($server);

        $imported = (new SavedTaskGraphPortability(
            $this->createStub(PromptRepository::class),
            $mcp,
        ))->importGraph([
            'nodes' => [
                ['id' => 'n1', 'capability' => 'tool_call', 'params' => ['tool' => 'mcp:Helpdesk:create']],
            ],
        ], 4);

        self::assertSame('mcp:Helpdesk:create', $imported['graph']['nodes'][0]['params']['tool'] ?? null);
        self::assertSame('needsConnection', $imported['checklist'][0]['code'] ?? null);
        self::assertSame('Helpdesk', $imported['checklist'][0]['detail'] ?? null);
    }

    public function testImportDoesNotBindANumericMcpId(): void
    {
        $imported = (new SavedTaskGraphPortability(
            $this->createStub(PromptRepository::class),
            $this->createStub(McpServerConfigRepository::class),
        ))->importGraph([
            'nodes' => [
                ['id' => 'n1', 'capability' => 'tool_call', 'params' => ['tool' => 'mcp:22:create']],
            ],
        ], 4);

        self::assertSame(
            'mcp:'.SavedTaskGraphPortability::MISSING_MCP_SERVER.':create',
            $imported['graph']['nodes'][0]['params']['tool'] ?? null,
        );
        self::assertSame('needsConnection', $imported['checklist'][0]['code'] ?? null);
    }

    public function testImportTreatsDisabledPromptAsMissing(): void
    {
        $prompt = new Prompt();
        $prompt->setTopic('support');
        (new \ReflectionProperty(Prompt::class, 'enabled'))->setValue($prompt, false);

        $prompts = $this->createMock(PromptRepository::class);
        $prompts->expects(self::any())->method('findByTopicAndUser')->with('support', 4)->willReturn($prompt);

        $imported = (new SavedTaskGraphPortability(
            $prompts,
            $this->createStub(McpServerConfigRepository::class),
        ))->importGraph([
            'nodes' => [
                ['id' => 'n1', 'capability' => 'chat', 'params' => ['prompt_topic' => 'support']],
            ],
        ], 4);

        self::assertSame('support', $imported['graph']['nodes'][0]['params']['topic_id'] ?? null);
        self::assertArrayNotHasKey('prompt_id', $imported['graph']['nodes'][0]['params']);
        self::assertSame('needsAssistant', $imported['checklist'][0]['code'] ?? null);
    }

    public function testImportTriggerKeepsOwnedMailbox(): void
    {
        $handler = $this->createMock(\App\Entity\InboundEmailHandler::class);
        $inbound = $this->createMock(\App\Repository\InboundEmailHandlerRepository::class);
        $inbound->expects(self::any())->method('findByIdAndUser')->with(77, 4)->willReturn($handler);

        $imported = (new SavedTaskGraphPortability(
            $this->createStub(PromptRepository::class),
            $this->createStub(McpServerConfigRepository::class),
            $inbound,
        ))->importTriggerConfig(['accountId' => 77, 'folder' => 'INBOX'], 'inbound_email', 4);

        self::assertSame(['folder' => 'INBOX', 'accountId' => 77], $imported['config']);
        self::assertSame([], $imported['checklist']);
    }

    public function testImportTriggerChecklistsAForeignMailbox(): void
    {
        $inbound = $this->createMock(\App\Repository\InboundEmailHandlerRepository::class);
        $inbound->method('findByIdAndUser')->willReturn(null);
        $inbound->method('findActiveByUser')->willReturn([]);

        $imported = (new SavedTaskGraphPortability(
            $this->createStub(PromptRepository::class),
            $this->createStub(McpServerConfigRepository::class),
            $inbound,
        ))->importTriggerConfig(['accountId' => 77], 'inbound_email', 4);

        self::assertArrayNotHasKey('accountId', $imported['config'] ?? []);
        self::assertSame('needsMailbox', $imported['checklist'][0]['code'] ?? null);
    }
}
