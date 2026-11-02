<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Execution;

use App\Entity\User;
use App\Service\Tool\Execution\GateMode;
use App\Service\Tool\Execution\GateRequest;
use App\Service\Tool\Execution\ToolCaller;
use App\Service\Tool\Execution\ToolExecution;
use App\Service\Tool\Execution\ToolExecutorInterface;
use App\Service\Tool\Execution\ToolInvocation;
use App\Service\Tool\Execution\ToolInvoker;
use App\Service\Tool\Execution\ToolMetrics;
use App\Service\Tool\Execution\ToolOutcomeKind;
use App\Service\Tool\Policy\PolicyContext;
use App\Service\Tool\Policy\PolicyOutcome;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolExecutionGate;
use App\Service\Tool\ToolsConfig;
use App\Service\Tool\ToolSource;
use PHPUnit\Framework\TestCase;

final class ToolInvokerTest extends TestCase
{
    public function testAsTodayNeverSkipsTheGateAndCountsTheCall(): void
    {
        $gate = $this->createMock(ToolExecutionGate::class);
        $gate->expects(self::never())->method('inspect');
        $executor = new class implements ToolExecutorInterface {
            public int $calls = 0;

            public function supports(ToolDescriptor $tool): bool
            {
                return true;
            }

            public function execute(ToolDescriptor $tool, ToolInvocation $invocation): ToolExecution
            {
                ++$this->calls;

                return new ToolExecution('ok', false, 'mcp', 'read');
            }
        };
        $metrics = new ToolMetrics();
        $invoker = new ToolInvoker([$executor], $metrics, gate: $gate);
        $outcome = $invoker->invoke($this->tool(), $this->call(GateMode::AsTodayNever));

        self::assertSame(ToolOutcomeKind::Done, $outcome->kind);
        self::assertSame('ok', $outcome->resultText);
        self::assertSame(1, $executor->calls);
        self::assertSame(1, $metrics->count('mcp', 'read', 'done'));
    }

    public function testApprovalsOffDoesNotInspect(): void
    {
        $gate = $this->createMock(ToolExecutionGate::class);
        $gate->expects(self::never())->method('inspect');
        $config = $this->createStub(ToolsConfig::class);
        $config->method('isApprovalsEnabled')->willReturn(false);
        $executor = new RecordingExecutor();
        $invoker = new ToolInvoker([$executor], gate: $gate, toolsConfig: $config);
        $outcome = $invoker->invoke($this->tool(), $this->call(GateMode::AsTodayWhenApprovalsOn, $this->request()));

        self::assertSame(ToolOutcomeKind::Done, $outcome->kind);
        self::assertSame(1, $executor->calls);
    }

    public function testDecideOnlyRunsTheGateAndSkipsTheExecutorAndTheCounter(): void
    {
        $gate = $this->createMock(ToolExecutionGate::class);
        $gate->expects(self::once())->method('inspect')->willReturn(['outcome' => PolicyOutcome::Auto, 'approval' => null, 'refusal' => null]);
        $config = $this->createStub(ToolsConfig::class);
        $config->method('isApprovalsEnabled')->willReturn(true);
        $executor = new RecordingExecutor();
        $metrics = new ToolMetrics();
        $invoker = new ToolInvoker([$executor], $metrics, gate: $gate, toolsConfig: $config);
        $call = $this->call(GateMode::AsTodayAlways, $this->request());
        $outcome = $invoker->invoke($this->tool(), new ToolInvocation(
            $call->toolName,
            $call->arguments,
            $call->caller,
            $call->gate,
            decision: $call->decision,
            decideOnly: true,
            user: $call->user,
        ));

        self::assertSame(ToolOutcomeKind::Done, $outcome->kind);
        self::assertSame('', $outcome->resultText);
        self::assertSame(0, $executor->calls);
        self::assertSame(0, $metrics->count('mcp', 'read', 'done'));
    }

    public function testABlockedNodeIsRefusedWhenTheGateIsAbsent(): void
    {
        $executor = new RecordingExecutor();
        $invoker = new ToolInvoker([$executor]);
        $request = new GateRequest(null, PolicyContext::Unattended, 'task_run:1:step', nodeApproval: 'block');
        $outcome = $invoker->invoke($this->tool(), $this->call(GateMode::AsTodayAlways, $request));

        self::assertSame(ToolOutcomeKind::Refused, $outcome->kind);
        self::assertSame(ToolExecutionGate::NODE_BLOCK_REFUSAL, $outcome->resultText);
        self::assertSame(0, $executor->calls);
    }

    public function testInspectingWithoutAnActorFailsClosed(): void
    {
        $gate = $this->createStub(ToolExecutionGate::class);
        $config = $this->createStub(ToolsConfig::class);
        $config->method('isApprovalsEnabled')->willReturn(true);
        $invoker = new ToolInvoker([new RecordingExecutor()], gate: $gate, toolsConfig: $config);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has no actor');
        $invoker->invoke($this->tool(), $this->call(GateMode::AsTodayWhenApprovalsOn, new GateRequest(null, PolicyContext::Interactive, 'chat:0')));
    }

    private function tool(): ToolDescriptor
    {
        return new ToolDescriptor('search', 'search', '', [], SideEffect::Read, ToolSource::Mcp, 7);
    }

    private function request(): GateRequest
    {
        return new GateRequest($this->createStub(User::class), PolicyContext::Interactive, 'chat:0');
    }

    private function call(GateMode $mode, ?GateRequest $request = null): ToolInvocation
    {
        return new ToolInvocation('search', [], new ToolCaller(7, 'messages_api'), $mode, decision: $request, user: $request?->actor);
    }
}
