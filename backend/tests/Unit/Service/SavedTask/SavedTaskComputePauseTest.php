<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SavedTask;

use App\Entity\Approval;
use App\Entity\ComputeRun;
use App\Entity\User;
use App\Repository\ComputeRunRepository;
use App\Repository\FileRepository;
use App\Repository\UserRepository;
use App\Service\Compute\ComputeArtefactStore;
use App\Service\Compute\ComputeClient;
use App\Service\Compute\ComputeConfig;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\Runner\CodeRunRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\RateLimitService;
use App\Service\Tool\Policy\PolicyOutcome;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolExecutionGate;
use App\Service\Tool\ToolSource;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SavedTaskComputePauseTest extends TestCase
{
    public function testUnattendedApprovePausesAndWritesApprovalId(): void
    {
        $saved = [];
        $runs = $this->createMock(ComputeRunRepository::class);
        $runs->method('save')->willReturnCallback(static function (ComputeRun $run) use (&$saved): void {
            $saved[] = $run;
        });
        $approval = $this->createStub(Approval::class);
        $approval->method('getId')->willReturn(44);
        $gate = $this->createMock(ToolExecutionGate::class);
        $gate->method('inspect')->willReturn([
            'outcome' => PolicyOutcome::Approve,
            'descriptor' => new ToolDescriptor('code_run', 'File work', '', [], SideEffect::Write, ToolSource::Compute, 0),
            'approval' => $approval,
            'refusal' => null,
        ]);

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($user);
        $config = $this->createStub(ComputeConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('clampLimits')->willReturn([
            'timeoutSec' => 10,
            'memoryMb' => 512,
            'cpu' => 1.0,
            'pids' => 128,
            'outputMb' => 50,
        ]);

        $runner = new CodeRunRunner(
            $config,
            $this->createStub(ComputeClient::class),
            $this->createStub(ComputeArtefactStore::class),
            $runs,
            $this->createStub(FileRepository::class),
            $users,
            $this->createStub(RateLimitService::class),
            new NullLogger(),
            '/tmp',
            $gate,
        );
        $message = $this->createStub(\App\Entity\Message::class);
        $message->method('getId')->willReturn(3);
        $context = new NodeContext($message, [], 7, [], ['saved_task_run_id' => 12]);

        $result = $runner->run(new TaskNode('n1', Capability::CodeRun, params: ['script' => 'print(1)']), $context);

        self::assertTrue($result->isWaitingApproval());
        self::assertSame(44, $result->metadata['approval_id']);
        self::assertNotEmpty($saved);
        self::assertSame(44, $saved[0]->getApprovalId());
        self::assertSame(ComputeRun::VIA_SAVED_TASK, $saved[0]->getInvokedVia());
    }

    public function testBlockIsReadableFailure(): void
    {
        $gate = $this->createMock(ToolExecutionGate::class);
        $gate->method('inspect')->willReturn([
            'outcome' => PolicyOutcome::Block,
            'descriptor' => new ToolDescriptor('code_run', 'File work', '', [], SideEffect::Write, ToolSource::Compute, 0),
            'approval' => null,
            'refusal' => 'I cannot do that. An administrator has turned this off.',
        ]);
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($user);
        $config = $this->createStub(ComputeConfig::class);
        $config->method('isEnabled')->willReturn(true);
        $runner = new CodeRunRunner(
            $config,
            $this->createStub(ComputeClient::class),
            $this->createStub(ComputeArtefactStore::class),
            $this->createStub(ComputeRunRepository::class),
            $this->createStub(FileRepository::class),
            $users,
            $this->createStub(RateLimitService::class),
            new NullLogger(),
            '/tmp',
            $gate,
        );
        $message = $this->createStub(\App\Entity\Message::class);
        $message->method('getId')->willReturn(3);
        $context = new NodeContext($message, [], 7, [], ['saved_task_run_id' => 12]);

        $result = $runner->run(new TaskNode('n1', Capability::CodeRun, params: ['script' => 'print(1)']), $context);

        self::assertFalse($result->isSuccessful());
        self::assertSame('I cannot do that. An administrator has turned this off.', $result->error);
    }
}
