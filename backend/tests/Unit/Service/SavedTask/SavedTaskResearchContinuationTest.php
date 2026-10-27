<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SavedTask;

use App\Entity\SavedTask;
use App\Entity\SavedTaskRun;
use App\Entity\User;
use App\Repository\SavedTaskRepository;
use App\Repository\SavedTaskRunRepository;
use App\Repository\UserRepository;
use App\Service\Multitask\Execution\DagExecutor;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeContextRehydrator;
use App\Service\Multitask\Plan\TaskPlan;
use App\Service\Multitask\TaskPlanStore;
use App\Service\RateLimitService;
use App\Service\SavedTask\Graph\SavedTaskPlanFactory;
use App\Service\SavedTask\SavedTaskResearchContinuation;
use App\Service\SavedTask\SavedTaskResumeReply;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SavedTaskResearchContinuationTest extends TestCase
{
    private const OWNER = 7;

    private SavedTaskRun $run;

    private DagExecutor&MockObject $dag;

    private SavedTaskResumeReply&MockObject $reply;

    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        $this->run = new SavedTaskRun(3, 'schedule');
        $this->run->markWaitingApproval('n1', 40, ['cards' => []]);
        $this->dag = $this->createMock(DagExecutor::class);
        $this->reply = $this->createMock(SavedTaskResumeReply::class);
    }

    public function testTheReportContinuesTheTaskAndTheResultIsPostedToTheChat(): void
    {
        $this->dag->expects(self::once())->method('resume')
            ->with(self::isInstanceOf(TaskPlan::class), self::isInstanceOf(NodeContext::class), 'n1', ['research_report' => 'Ferries run hourly.'])
            ->willReturn(['content' => 'Mail sent.', 'node_statuses' => ['n1' => 'done', 'n2' => 'done'], 'all_failed' => false]);
        $this->reply->expects(self::once())->method('post')->with(
            self::isInstanceOf(SavedTask::class),
            self::callback(static fn (array $assembled): bool => 'Mail sent.' === $assembled['content']),
            'Ferries run hourly.',
        );

        $this->continuation()->complete(9, 'n1', self::OWNER, ['research_report' => 'Ferries run hourly.']);

        self::assertSame(SavedTaskRun::STATUS_COMPLETED, $this->run->getStatus());
        self::assertTrue($this->options['saved_task'] ?? false, 'Later steps must run under the unattended policy.');
    }

    public function testAFailedResearchFailsTheRunWithItsSentence(): void
    {
        $this->dag->method('resume')->willReturn(['content' => '', 'node_statuses' => ['n1' => 'failed', 'n2' => 'skipped'], 'all_failed' => true]);
        $this->reply->expects(self::never())->method('post');

        $this->continuation()->complete(9, 'n1', self::OWNER, ['research_failed' => 'Nothing was found.']);

        self::assertSame(SavedTaskRun::STATUS_FAILED, $this->run->getStatus());
        self::assertSame('Nothing was found.', $this->run->getError());
    }

    public function testAResearchRunOfAnotherPersonDoesNotContinueTheTask(): void
    {
        $this->dag->expects(self::never())->method('resume');

        self::assertNull($this->continuation()->complete(9, 'n1', 99, ['research_report' => 'x']));
        self::assertSame(SavedTaskRun::STATUS_WAITING_APPROVAL, $this->run->getStatus());
    }

    public function testARunWaitingOnAnotherStepIsLeftAlone(): void
    {
        $this->dag->expects(self::never())->method('resume');

        self::assertNull($this->continuation()->complete(9, 'n2', self::OWNER, ['research_report' => 'x']));
        $this->continuation()->fail(9, 'n2', 'Gave up.');

        self::assertSame(SavedTaskRun::STATUS_WAITING_APPROVAL, $this->run->getStatus());
    }

    public function testASettleErrorFailsTheRunInsteadOfRunningTheStepsAgain(): void
    {
        $this->dag->expects(self::once())->method('resume')->willReturn(['content' => 'Mail sent.', 'node_statuses' => ['n1' => 'done'], 'all_failed' => false]);
        $this->reply->method('post')->willThrowException(new \RuntimeException('Chat is gone'));

        $this->continuation()->complete(9, 'n1', self::OWNER, ['research_report' => 'Ferries run hourly.']);

        self::assertSame(SavedTaskRun::STATUS_FAILED, $this->run->getStatus());
        self::assertStringContainsString('Check the chat', (string) $this->run->getError());
    }

    private function continuation(): SavedTaskResearchContinuation
    {
        $runs = $this->createStub(SavedTaskRunRepository::class);
        $runs->method('find')->willReturn($this->run);
        $task = new SavedTask(self::OWNER, 12, 'Ferry brief');
        $tasks = $this->createStub(SavedTaskRepository::class);
        $tasks->method('find')->willReturn($task);
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($this->createStub(User::class));
        $plans = $this->createStub(SavedTaskPlanFactory::class);
        $plans->method('fromTask')->willReturn(TaskPlan::fromArray([
            'version' => 1,
            'language' => 'en',
            'reply_node' => 'n2',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'research'],
                ['id' => 'n2', 'capability' => 'email_me', 'depends_on' => ['n1']],
            ],
        ]));
        $rehydrator = $this->createStub(NodeContextRehydrator::class);
        $rehydrator->method('fromRun')->willReturnCallback(function (SavedTaskRun $run, array $options): NodeContext {
            $this->options = $options;

            return $this->createStub(NodeContext::class);
        });
        $limits = $this->createStub(RateLimitService::class);
        $limits->method('checkLimit')->willReturn(['allowed' => true]);

        return new SavedTaskResearchContinuation(
            $runs,
            $tasks,
            $users,
            $plans,
            $rehydrator,
            $this->dag,
            $this->createStub(TaskPlanStore::class),
            $limits,
            $this->reply,
            new NullLogger(),
        );
    }
}
