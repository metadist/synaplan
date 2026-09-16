<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Entity\Message;
use App\Repository\CustomToolRepository;
use App\Repository\McpServerConfigRepository;
use App\Repository\UserRepository;
use App\Service\Mcp\McpClient;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\Runner\ToolCallRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\SavedTask\Graph\StepInputResolver;
use App\Service\Tool\Custom\HttpToolExecutor;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolRegistry;
use App\Service\Tool\ToolsConfig;
use App\Service\Tool\ToolSource;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ToolCallRunnerTest extends TestCase
{
    public function testUnregisteredToolFailsClearly(): void
    {
        $registry = $this->createMock(ToolRegistry::class);
        $registry->method('get')->willReturn(null);
        $runner = new ToolCallRunner(
            $registry,
            new StepInputResolver(),
            $this->createMock(HttpToolExecutor::class),
            $this->createMock(CustomToolRepository::class),
            $this->createMock(McpClient::class),
            $this->createMock(McpServerConfigRepository::class),
            $this->createMock(UserRepository::class),
            new NullLogger(),
        );
        $message = new Message();
        $message->setUserId(4);
        $result = $runner->run(
            new TaskNode('t1', Capability::ToolCall, [], [], ['tool' => 'custom:gone']),
            new NodeContext($message, [], 4, ['language' => 'en']),
        );

        self::assertFalse($result->isSuccessful());
        self::assertNotNull($result->error);
        self::assertStringContainsString('gone', $result->error);
    }

    public function testPlannerNoteListsCustomToolsAndStaysHiddenWithoutAny(): void
    {
        $empty = $this->runner($this->createMock(ToolRegistry::class));
        self::assertTrue($empty->describe()[0]->requiresDynamicNote);
        self::assertNull(($empty->describe()[0]->dynamicNote)(4, []));

        $registry = $this->createMock(ToolRegistry::class);
        $registry->method('forUser')->willReturn([
            new ToolDescriptor(
                'custom:walk_ticket_create',
                'Create a ticket',
                '',
                [],
                SideEffect::Write,
                ToolSource::Custom,
                4,
            ),
        ]);
        $note = ($this->runner($registry)->describe()[0]->dynamicNote)(4, []);
        self::assertNotNull($note);
        self::assertStringContainsString('custom:walk_ticket_create', $note);
        self::assertStringNotContainsString('MCP', $note);
    }

    public function testPlannerNoteHidesCustomToolsWhenRegistryIsDisabled(): void
    {
        $registry = $this->createMock(ToolRegistry::class);
        $registry->expects(self::never())->method('forUser');
        $toolsConfig = $this->createMock(ToolsConfig::class);
        $toolsConfig->method('isRegistryEnabled')->willReturn(false);
        $toolsConfig->method('isCustomHttpEnabled')->willReturn(true);

        $note = ($this->runner($registry, $toolsConfig)->describe()[0]->dynamicNote)(4, []);
        self::assertNull($note);
    }

    public function testCustomRunFailsWhenRegistryIsDisabled(): void
    {
        $registry = $this->createMock(ToolRegistry::class);
        $registry->method('get')->willReturn(new ToolDescriptor(
            'custom:walk_ticket_create',
            'Create a ticket',
            '',
            [],
            SideEffect::Write,
            ToolSource::Custom,
            4,
            meta: ['toolId' => 9],
        ));
        $toolsConfig = $this->createMock(ToolsConfig::class);
        $toolsConfig->method('isRegistryEnabled')->willReturn(false);
        $toolsConfig->method('isCustomHttpEnabled')->willReturn(true);

        $message = new Message();
        $message->setUserId(4);
        $result = $this->runner($registry, $toolsConfig)->run(
            new TaskNode('t1', Capability::ToolCall, [], [], ['tool' => 'custom:walk_ticket_create']),
            new NodeContext($message, [], 4, ['language' => 'en']),
        );

        self::assertFalse($result->isSuccessful());
        self::assertSame('Custom tools are turned off', $result->error);
    }

    private function runner(ToolRegistry $registry, ?ToolsConfig $toolsConfig = null): ToolCallRunner
    {
        return new ToolCallRunner(
            $registry,
            new StepInputResolver(),
            $this->createMock(HttpToolExecutor::class),
            $this->createMock(CustomToolRepository::class),
            $this->createMock(McpClient::class),
            $this->createMock(McpServerConfigRepository::class),
            $this->createMock(UserRepository::class),
            new NullLogger(),
            toolsConfig: $toolsConfig,
        );
    }
}
