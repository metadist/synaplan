<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SavedTask;

use App\Entity\Approval;
use App\Entity\SavedTask;
use App\Entity\SavedTaskRun;
use App\Entity\User;
use App\Repository\ApprovalRepository;
use App\Repository\SavedTaskRepository;
use App\Repository\SavedTaskRunRepository;
use App\Repository\UserRepository;
use App\Service\Multitask\Execution\DagExecutor;
use App\Service\Multitask\Execution\NodeContextRehydrator;
use App\Service\Multitask\TaskPlanStore;
use App\Service\RateLimitService;
use App\Service\SavedTask\Graph\SavedTaskPlanFactory;
use App\Service\SavedTask\Graph\StepInputResolver;
use App\Service\SavedTask\SavedTaskNotWaitingException;
use App\Service\SavedTask\SavedTaskResumeService;
use App\Service\Tool\Policy\ApprovalPolicy;
use App\Service\Tool\ToolRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Resuming a paused run must only ever accept the approval that run paused for.
 */
final class SavedTaskResumeServiceTest extends TestCase
{
    /**
     * @return iterable<string, array{Approval, string}>
     */
    public static function mismatchedApprovals(): iterable
    {
        yield 'another owner' => [self::approval(ownerId: 77, requestedBy: 'task_run:8:n2', approved: true), 'n2'];
        yield 'still pending' => [self::approval(ownerId: 9, requestedBy: 'task_run:8:n2', approved: false), 'n2'];
        yield 'another run' => [self::approval(ownerId: 9, requestedBy: 'task_run:99:n2', approved: true), 'n2'];
        yield 'another step' => [self::approval(ownerId: 9, requestedBy: 'task_run:8:n2', approved: true), 'n3'];
        yield 'a chat approval' => [self::approval(ownerId: 9, requestedBy: 'chat:123', approved: true), 'n2'];
    }

    #[DataProvider('mismatchedApprovals')]
    public function testAnApprovalThatDoesNotBelongToThisRunAndStepIsRefused(Approval $approval, string $nodeId): void
    {
        $run = new SavedTaskRun(5, 'webhook');
        $run->markWaitingApproval('n2');
        (new \ReflectionProperty(SavedTaskRun::class, 'id'))->setValue($run, 8);
        $task = new SavedTask(9, 12, 'Paused');

        $runs = $this->createMock(SavedTaskRunRepository::class);
        $runs->method('find')->willReturn($run);
        $tasks = $this->createMock(SavedTaskRepository::class);
        $tasks->method('find')->willReturn($task);
        $users = $this->createMock(UserRepository::class);
        $users->method('find')->willReturn($this->createMock(User::class));
        $approvals = $this->createMock(ApprovalRepository::class);
        $approvals->method('find')->willReturn($approval);

        $registry = $this->createMock(ToolRegistry::class);
        $registry->expects(self::never())->method('get');
        $executor = $this->createMock(DagExecutor::class);
        $executor->expects(self::never())->method('resume');

        $service = new SavedTaskResumeService(
            $runs,
            $tasks,
            $users,
            $approvals,
            $this->createStub(SavedTaskPlanFactory::class),
            $this->createStub(NodeContextRehydrator::class),
            $executor,
            $this->createStub(TaskPlanStore::class),
            $this->createStub(RateLimitService::class),
            $registry,
            new NullLogger(),
            $this->createStub(ApprovalPolicy::class),
            new StepInputResolver(),
        );

        $this->expectException(SavedTaskNotWaitingException::class);

        $service->resume(8, $nodeId, 1);
    }

    private static function approval(int $ownerId, string $requestedBy, bool $approved): Approval
    {
        $approval = new Approval($ownerId, $requestedBy, 'custom:crm', 'write', time() + 3600);
        if ($approved) {
            $approval->markApproved($ownerId);
        }

        return $approval;
    }
}
