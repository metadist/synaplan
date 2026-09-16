<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Compute;

use App\Service\Compute\ComputeConfig;
use App\Service\Tool\Policy\ApprovalPolicy;
use App\Service\Tool\Policy\AssistantPolicyProviderInterface;
use App\Service\Tool\Policy\NullGroupPolicyProvider;
use App\Service\Tool\Policy\PolicyContext;
use App\Service\Tool\Policy\PolicyOutcome;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolsConfig;
use App\Service\Tool\ToolSource;
use PHPUnit\Framework\TestCase;

final class ComputePolicyTest extends TestCase
{
    public function testInteractiveDefaultAuto(): void
    {
        $policy = $this->policy();
        self::assertSame(PolicyOutcome::Auto, $policy->decide($this->tool(), 1, PolicyContext::Interactive));
    }

    public function testUnattendedDefaultApprove(): void
    {
        $policy = $this->policy();
        self::assertSame(PolicyOutcome::Approve, $policy->decide($this->tool(), 1, PolicyContext::Unattended));
    }

    public function testHosterApproveCannotBeLoosened(): void
    {
        $compute = $this->createMock(ComputeConfig::class);
        $compute->method('policyInteractive')->willReturn(ComputeConfig::POLICY_APPROVE);
        $compute->method('policyUnattended')->willReturn(ComputeConfig::POLICY_APPROVE);
        $assistant = $this->createMock(AssistantPolicyProviderInterface::class);
        $assistant->method('outcomeFor')->willReturn(PolicyOutcome::Auto);
        $policy = new ApprovalPolicy($this->tools(), new NullGroupPolicyProvider(), $assistant, $compute);

        self::assertSame(PolicyOutcome::Approve, $policy->decide($this->tool(), 1, PolicyContext::Interactive));
    }

    public function testEgressNeverLoosensDecision(): void
    {
        $compute = $this->createMock(ComputeConfig::class);
        $compute->method('policyInteractive')->willReturn(ComputeConfig::POLICY_AUTO);
        $compute->method('policyUnattended')->willReturn(ComputeConfig::POLICY_APPROVE);
        $compute->method('egressRequiresApproval')->willReturn(true);
        $assistant = $this->createMock(AssistantPolicyProviderInterface::class);
        $assistant->method('outcomeFor')->willReturn(PolicyOutcome::Auto);
        $policy = new ApprovalPolicy($this->tools(), new NullGroupPolicyProvider(), $assistant, $compute);

        self::assertSame(PolicyOutcome::Auto, $policy->decide($this->tool(), 1, PolicyContext::Interactive));
        self::assertSame(
            PolicyOutcome::Approve,
            $policy->decide($this->tool(), 1, PolicyContext::Interactive, null, false, null, PolicyOutcome::Approve),
        );
        $computeBlock = $this->createMock(ComputeConfig::class);
        $computeBlock->method('policyInteractive')->willReturn(ComputeConfig::POLICY_BLOCK);
        $computeBlock->method('policyUnattended')->willReturn(ComputeConfig::POLICY_BLOCK);
        $blocked = new ApprovalPolicy($this->tools(), new NullGroupPolicyProvider(), $assistant, $computeBlock);
        self::assertSame(
            PolicyOutcome::Block,
            $blocked->decide($this->tool(), 1, PolicyContext::Interactive, null, false, null, PolicyOutcome::Approve),
        );
    }

    public function testBlockWins(): void
    {
        $compute = $this->createMock(ComputeConfig::class);
        $compute->method('policyInteractive')->willReturn(ComputeConfig::POLICY_BLOCK);
        $compute->method('policyUnattended')->willReturn(ComputeConfig::POLICY_BLOCK);
        $assistant = $this->createMock(AssistantPolicyProviderInterface::class);
        $assistant->method('outcomeFor')->willReturn(PolicyOutcome::Auto);
        $policy = new ApprovalPolicy($this->tools(), new NullGroupPolicyProvider(), $assistant, $compute);

        self::assertSame(PolicyOutcome::Block, $policy->decide($this->tool(), 1, PolicyContext::Interactive, null, true));
    }

    private function policy(): ApprovalPolicy
    {
        $compute = $this->createMock(ComputeConfig::class);
        $compute->method('policyInteractive')->willReturn(ComputeConfig::POLICY_AUTO);
        $compute->method('policyUnattended')->willReturn(ComputeConfig::POLICY_APPROVE);
        $assistant = $this->createMock(AssistantPolicyProviderInterface::class);
        $assistant->method('outcomeFor')->willReturn(null);

        return new ApprovalPolicy($this->tools(), new NullGroupPolicyProvider(), $assistant, $compute);
    }

    private function tools(): ToolsConfig
    {
        $config = $this->createMock(ToolsConfig::class);
        $config->method('defaultOutcome')->willReturn(PolicyOutcome::Approve);
        $config->method('alwaysAllowTools')->willReturn([]);

        return $config;
    }

    private function tool(): ToolDescriptor
    {
        return new ToolDescriptor('code_run', 'File work', '', [], SideEffect::Write, ToolSource::Compute, 0);
    }
}
