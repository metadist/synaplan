<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool;

use App\Entity\Approval;
use App\Entity\User;
use App\Service\Tool\ApprovalService;
use App\Service\Tool\Policy\ApprovalPolicy;
use App\Service\Tool\Policy\PolicyContext;
use App\Service\Tool\Policy\PolicyOutcome;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolExecutionGate;
use App\Service\Tool\ToolRegistry;
use App\Service\Tool\ToolsConfig;
use App\Service\Tool\ToolSource;
use PHPUnit\Framework\TestCase;

final class ToolExecutionGateTest extends TestCase
{
    public function testRequireApprovalLiftsAutoToApproveAndRequestsOne(): void
    {
        $approval = $this->createStub(Approval::class);
        $approvals = $this->createMock(ApprovalService::class);
        $approvals->expects(self::once())->method('request')->willReturn($approval);

        $gate = $this->gate(PolicyOutcome::Auto, $approvals);
        $decision = $gate->inspect(7, 'code_run', [], $this->createStub(User::class), PolicyContext::Unattended, 'chat', null, true, 'assistant:1', null, true);

        self::assertSame(PolicyOutcome::Approve, $decision['outcome']);
        self::assertSame($approval, $decision['approval']);
    }

    public function testRequireApprovalNeverLiftsBlock(): void
    {
        $approvals = $this->createMock(ApprovalService::class);
        $approvals->expects(self::never())->method('request');

        $decision = $this->gate(PolicyOutcome::Block, $approvals)
            ->inspect(7, 'code_run', [], $this->createStub(User::class), PolicyContext::Interactive, 'chat', requireApproval: true);

        self::assertSame(PolicyOutcome::Block, $decision['outcome']);
        self::assertNotNull($decision['refusal']);
    }

    public function testWithoutRequireApprovalAutoStaysAuto(): void
    {
        $approvals = $this->createMock(ApprovalService::class);
        $approvals->expects(self::never())->method('request');

        $decision = $this->gate(PolicyOutcome::Auto, $approvals)
            ->inspect(7, 'code_run', [], $this->createStub(User::class), PolicyContext::Interactive, 'chat');

        self::assertSame(PolicyOutcome::Auto, $decision['outcome']);
    }

    public function testApprovalsEnabledMirrorsToolsConfig(): void
    {
        $config = $this->createStub(ToolsConfig::class);
        $config->method('isApprovalsEnabled')->willReturn(false);
        $gate = new ToolExecutionGate(
            $config,
            $this->createStub(ToolRegistry::class),
            $this->createStub(ApprovalPolicy::class),
            $this->createStub(ApprovalService::class),
        );

        self::assertFalse($gate->approvalsEnabled(7));
    }

    private function gate(PolicyOutcome $policySays, ApprovalService $approvals): ToolExecutionGate
    {
        $config = $this->createStub(ToolsConfig::class);
        $config->method('isApprovalsEnabled')->willReturn(true);

        $registry = $this->createStub(ToolRegistry::class);
        $registry->method('get')->willReturn(
            new ToolDescriptor('code_run', 'File work', '', [], SideEffect::Write, ToolSource::Compute, 0),
        );

        $policy = $this->createStub(ApprovalPolicy::class);
        $policy->method('decide')->willReturn($policySays);

        return new ToolExecutionGate($config, $registry, $policy, $approvals);
    }
}
