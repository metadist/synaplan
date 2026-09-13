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

    public function testUnknownItemKeys(): void
    {
        $port = new SavedTaskGraphPortability(
            $this->createStub(PromptRepository::class),
            $this->createStub(McpServerConfigRepository::class),
        );

        self::assertSame(['token'], $port->unknownItemKeys(['name' => 'A', 'token' => 'nope']));
    }
}
