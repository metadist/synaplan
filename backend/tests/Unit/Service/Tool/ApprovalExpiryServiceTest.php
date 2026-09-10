<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool;

use App\Entity\Approval;
use App\Entity\SavedTask;
use App\Entity\SavedTaskRun;
use App\Repository\ApprovalRepository;
use App\Repository\SavedTaskRepository;
use App\Repository\SavedTaskRunRepository;
use App\Repository\UserRepository;
use App\Service\Tool\ApprovalExpiryService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ApprovalExpiryServiceTest extends TestCase
{
    public function testExpiredTaskRunFailsWithReadableReason(): void
    {
        $approval = new Approval(7, 'task_run:44:n2', 'mcp:1:create', 'write', time() - 10);
        $run = new SavedTaskRun(9, 'schedule');
        $run->markWaitingApproval('n2');
        $task = new SavedTask(7, 1, 'Weekly digest');

        $approvals = $this->createMock(ApprovalRepository::class);
        $approvals->method('findExpiredPending')->willReturn([$approval]);
        $approvals->expects($this->once())->method('flush');

        $runs = $this->createMock(SavedTaskRunRepository::class);
        $runs->method('find')->willReturnCallback(static fn (int $id): ?SavedTaskRun => 44 === $id ? $run : null);
        $runs->expects($this->once())->method('save')->with($run);

        $tasks = $this->createMock(SavedTaskRepository::class);
        $tasks->method('find')->willReturnCallback(static fn (int $id): ?SavedTask => 9 === $id ? $task : null);
        $tasks->expects($this->once())->method('save')->with($task);

        $service = new ApprovalExpiryService(
            $approvals,
            $runs,
            $tasks,
            $this->createMock(UserRepository::class),
            new NullLogger(),
        );

        $count = $service->sweep(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $this->assertSame(1, $count);
        $this->assertSame(Approval::STATUS_EXPIRED, $approval->getStatus());
        $this->assertSame(SavedTaskRun::STATUS_FAILED, $run->getStatus());
        $this->assertSame('Nobody approved in time', $run->getError());
        $this->assertNull($run->getWaitingNode());
        $this->assertSame(1, $task->getConsecutiveFailures());
    }
}
