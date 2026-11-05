<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Entity\ComputeRun;
use App\Entity\User;
use App\Repository\ComputeRunRepository;
use App\Repository\FileRepository;
use App\Repository\UserRepository;
use App\Service\Compute\ComputeArtefactStore;
use App\Service\Compute\ComputeClient;
use App\Service\Compute\ComputeConfig;
use App\Service\Multitask\Execution\Runner\CodeRunRunner;
use App\Service\RateLimitService;
use App\Service\Tool\Execution\ToolInvoker;
use App\Service\Tool\Execution\ToolOutcomeKind;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CodeRunAuditTest extends TestCase
{
    public function testTheAuditRowUsesTheSidecarDuration(): void
    {
        $stored = new ComputeRun(7, 'run-1', 'planner', 'python', 'python', []);
        $stored->setDurationMs(2500);
        $runs = $this->createMock(ComputeRunRepository::class);
        $runs->expects(self::once())->method('findOneByRunId')->with('run-1')->willReturn($stored);
        $invoker = $this->createMock(ToolInvoker::class);
        $invoker->expects(self::once())->method('recordRan')->with(
            self::anything(),
            7,
            'task_run:4:n1',
            ToolOutcomeKind::Done,
            2500,
        );
        $runner = new CodeRunRunner(
            $this->createStub(ComputeConfig::class),
            $this->createStub(ComputeClient::class),
            $this->createStub(ComputeArtefactStore::class),
            $runs,
            $this->createStub(FileRepository::class),
            $this->createStub(UserRepository::class),
            $this->createStub(RateLimitService::class),
            new NullLogger(),
            '/tmp',
        );
        $runner->setToolInvoker($invoker);
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        $runner->noteFinished($user, ['outcome' => 'ok', 'compute_run_id' => 'run-1'], 'task_run:4:n1');
    }
}
