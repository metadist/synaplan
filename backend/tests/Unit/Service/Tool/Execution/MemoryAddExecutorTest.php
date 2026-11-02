<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Execution;

use App\DTO\UserMemoryDTO;
use App\Entity\User;
use App\Service\Memory\UserMemoryService;
use App\Service\RateLimitService;
use App\Service\Tool\Execution\MemoryAddExecutor;
use App\Service\Tool\Execution\ToolChannel;
use App\Service\Tool\ToolSource;
use PHPUnit\Framework\TestCase;

final class MemoryAddExecutorTest extends TestCase
{
    use ToolExecutionExamples;

    public function testARateLimitStopsBeforeAMemoryIsCreated(): void
    {
        $memories = $this->createMock(UserMemoryService::class);
        $memories->expects(self::never())->method('createMemory');
        $rates = $this->createStub(RateLimitService::class);
        $rates->method('checkLimit')->willReturn(['allowed' => false]);
        $executor = new MemoryAddExecutor($memories, $rates);
        $result = $executor->execute(
            $this->descriptor(ToolSource::Plugin, 'memory_add'),
            $this->invocation(ToolChannel::MCP_SERVER, 'memory_add', ['category' => 'work', 'key' => 'q', 'value' => 'closed'], $this->createStub(User::class)),
        );

        self::assertSame('Rate limit exceeded for embeddings.', $result->text);
        self::assertTrue($result->isError);
    }

    public function testSuccessReturnsTheMemoryPayload(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $memories = $this->createMock(UserMemoryService::class);
        $memories->method('isAvailable')->willReturn(true);
        $memories->expects(self::once())->method('createMemory')->willReturn(new UserMemoryDTO(id: 5, userId: 7, category: 'work', key: 'q', value: 'closed'));
        $rates = $this->createStub(RateLimitService::class);
        $rates->method('checkLimit')->willReturn(['allowed' => true]);
        $executor = new MemoryAddExecutor($memories, $rates);
        $result = $executor->execute(
            $this->descriptor(ToolSource::Plugin, 'memory_add'),
            $this->invocation(ToolChannel::MCP_SERVER, 'memory_add', ['category' => 'work', 'key' => 'q', 'value' => 'closed'], $user),
        );

        self::assertFalse($result->isError);
        self::assertSame(5, $result->details['payload']['memory']['id']);
    }

    public function testItIgnoresEveryOtherPluginName(): void
    {
        $executor = new MemoryAddExecutor($this->createStub(UserMemoryService::class), $this->createStub(RateLimitService::class));

        self::assertFalse($executor->supports($this->descriptor(ToolSource::Plugin, 'file_ingest')));
    }
}
