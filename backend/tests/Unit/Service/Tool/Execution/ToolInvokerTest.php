<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Execution;

use App\Entity\User;
use App\Service\Iam\AuditLogWriter;
use App\Service\RateLimitService;
use App\Service\Tool\Execution\GateRequest;
use App\Service\Tool\Execution\ToolCaller;
use App\Service\Tool\Execution\ToolInvocation;
use App\Service\Tool\Execution\ToolInvoker;
use App\Service\Tool\Execution\ToolMetrics;
use App\Service\Tool\Execution\ToolOutcomeKind;
use App\Service\Tool\Policy\PolicyContext;
use App\Service\Tool\Policy\PolicyOutcome;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolExecutionGate;
use App\Service\Tool\ToolSource;
use App\Service\Usage\RecordedUsage;
use PHPUnit\Framework\TestCase;

final class ToolInvokerTest extends TestCase
{
    public function testTheGateRunsAndAReadIsNotAudited(): void
    {
        $gate = $this->createMock(ToolExecutionGate::class);
        $gate->expects(self::once())->method('inspect')->willReturn([
            'outcome' => PolicyOutcome::Auto,
            'descriptor' => $this->tool(),
            'approval' => null,
            'refusal' => null,
        ]);
        $executor = new RecordingExecutor();
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::never())->method('record');
        $metrics = new ToolMetrics();
        $invoker = new ToolInvoker([$executor], $metrics, gate: $gate, audit: $audit);
        $outcome = $invoker->invoke($this->tool(), $this->call($this->request()));

        self::assertSame(ToolOutcomeKind::Done, $outcome->kind);
        self::assertSame('ok', $outcome->resultText);
        self::assertSame(1, $executor->calls);
        self::assertSame(1, $metrics->count('mcp', 'read', 'done'));
    }

    public function testAWriteLeavesAnAuditRowAndNoArguments(): void
    {
        $gate = $this->createMock(ToolExecutionGate::class);
        $write = new ToolDescriptor('create_ticket', 'Create ticket', '', [], SideEffect::Write, ToolSource::Custom, 7);
        $gate->expects(self::once())->method('inspect')->willReturn([
            'outcome' => PolicyOutcome::Auto,
            'descriptor' => $write,
            'approval' => null,
            'refusal' => null,
        ]);
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::once())->method('record')->with(
            7,
            'tool.executed',
            'tool',
            'create_ticket',
            self::callback(static function (array $subject): bool {
                return 'create_ticket' === $subject['tool']
                    && 'write' === $subject['kind']
                    && 'done' === $subject['outcome']
                    && !isset($subject['arguments'], $subject['result']);
            }),
        );
        $invoker = new ToolInvoker([new RecordingExecutor()], gate: $gate, audit: $audit);
        $outcome = $invoker->invoke($write, $this->call($this->request(), 'create_ticket'));

        self::assertSame(ToolOutcomeKind::Done, $outcome->kind);
    }

    public function testRecordUsageWritesOneToolsRow(): void
    {
        $gate = $this->createMock(ToolExecutionGate::class);
        $gate->expects(self::once())->method('inspect')->willReturn([
            'outcome' => PolicyOutcome::Auto,
            'descriptor' => new ToolDescriptor('mcp__1__rag_search', 'rag_search', '', [], SideEffect::Read, ToolSource::Mcp, 7, null, [
                'serverId' => 1,
                'tool' => 'rag_search',
            ]),
            'approval' => null,
            'refusal' => null,
        ]);
        $rates = $this->createMock(RateLimitService::class);
        $rates->expects(self::once())->method('recordUsage')->with(
            self::isInstanceOf(User::class),
            'TOOLS',
            self::callback(static fn (array $metadata): bool => 'MCP_TOOL' === ($metadata['source'] ?? null)
                && 'rag_search' === ($metadata['input_text'] ?? null)
                && !isset($metadata['arguments'])),
        )->willReturn(new RecordedUsage('0.000000', '0.000000', 0, 0, 0));
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $invoker = new ToolInvoker([new RecordingExecutor()], gate: $gate, rates: $rates);
        $tool = new ToolDescriptor('mcp__1__rag_search', 'rag_search', '', [], SideEffect::Read, ToolSource::Mcp, 7, null, [
            'serverId' => 1,
            'tool' => 'rag_search',
        ]);
        $call = new ToolInvocation(
            'mcp__1__rag_search',
            ['query' => 'secret'],
            new ToolCaller(7, 'openai_api'),
            recordUsage: true,
            decision: new GateRequest($user, PolicyContext::Interactive, 'chat:0'),
            user: $user,
        );
        $invoker->invoke($tool, $call);
    }

    public function testAReadFallbackStillAuditsWhenTheGateSaysWrite(): void
    {
        $write = new ToolDescriptor('mcp__1__create_issue', 'Create issue', '', [], SideEffect::Write, ToolSource::Mcp, 7);
        $gate = $this->createMock(ToolExecutionGate::class);
        $gate->expects(self::once())->method('inspect')->willReturn([
            'outcome' => PolicyOutcome::Auto,
            'descriptor' => $write,
            'approval' => null,
            'refusal' => null,
        ]);
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::once())->method('record')->with(
            7,
            'tool.executed',
            'tool',
            'mcp__1__create_issue',
            self::callback(static fn (array $subject): bool => 'write' === $subject['kind']),
        );
        $fallback = new ToolDescriptor('mcp__1__create_issue', 'Create issue', '', [], SideEffect::Read, ToolSource::Mcp, 7);
        $invoker = new ToolInvoker([new RecordingExecutor()], gate: $gate, audit: $audit);
        $invoker->invoke($fallback, $this->call($this->request(), 'mcp__1__create_issue'));
    }

    public function testDecideOnlyRunsTheGateAndSkipsTheExecutorAndTheCounter(): void
    {
        $gate = $this->createMock(ToolExecutionGate::class);
        $gate->expects(self::once())->method('inspect')->willReturn([
            'outcome' => PolicyOutcome::Auto,
            'descriptor' => new ToolDescriptor('code_run', 'File work', '', [], SideEffect::Write, ToolSource::Compute, 7),
            'approval' => null,
            'refusal' => null,
        ]);
        $executor = new RecordingExecutor();
        $metrics = new ToolMetrics();
        $audit = $this->createMock(AuditLogWriter::class);
        $audit->expects(self::never())->method('record');
        $invoker = new ToolInvoker([$executor], $metrics, gate: $gate, audit: $audit);
        $call = $this->call($this->request());
        $tool = new ToolDescriptor('code_run', 'File work', '', [], SideEffect::Write, ToolSource::Compute, 7);
        $outcome = $invoker->invoke($tool, new ToolInvocation(
            $call->toolName,
            $call->arguments,
            $call->caller,
            decision: $call->decision,
            decideOnly: true,
            user: $call->user,
        ));

        self::assertSame(ToolOutcomeKind::Done, $outcome->kind);
        self::assertSame('', $outcome->resultText);
        self::assertSame(0, $executor->calls);
        self::assertSame(0, $metrics->count('compute', 'write', 'done'));
    }

    public function testABlockedNodeIsRefusedWhenTheGateIsAbsent(): void
    {
        $executor = new RecordingExecutor();
        $invoker = new ToolInvoker([$executor]);
        $request = new GateRequest(null, PolicyContext::Unattended, 'task_run:1:step', nodeApproval: 'block');
        $outcome = $invoker->invoke($this->tool(), $this->call($request));

        self::assertSame(ToolOutcomeKind::Refused, $outcome->kind);
        self::assertSame(ToolExecutionGate::NODE_BLOCK_REFUSAL, $outcome->resultText);
        self::assertSame(0, $executor->calls);
    }

    public function testInspectingWithoutAnActorFailsClosed(): void
    {
        $gate = $this->createStub(ToolExecutionGate::class);
        $invoker = new ToolInvoker([new RecordingExecutor()], gate: $gate);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has no actor');
        $invoker->invoke($this->tool(), $this->call(new GateRequest(null, PolicyContext::Interactive, 'chat:0')));
    }

    private function tool(): ToolDescriptor
    {
        return new ToolDescriptor('search', 'search', '', [], SideEffect::Read, ToolSource::Mcp, 7);
    }

    private function request(): GateRequest
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);

        return new GateRequest($user, PolicyContext::Interactive, 'chat:0');
    }

    private function call(GateRequest $request, string $name = 'search'): ToolInvocation
    {
        return new ToolInvocation($name, [], new ToolCaller(7, 'messages_api'), decision: $request, user: $request->actor);
    }
}
