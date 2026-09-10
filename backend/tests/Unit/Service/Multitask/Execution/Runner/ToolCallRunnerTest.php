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
use App\Service\Tool\ToolRegistry;
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
}
