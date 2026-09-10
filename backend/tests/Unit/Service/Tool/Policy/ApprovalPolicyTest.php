<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Policy;

use App\Service\Tool\Policy\ApprovalPolicy;
use App\Service\Tool\Policy\AssistantPolicyProviderInterface;
use App\Service\Tool\Policy\GroupPolicyProviderInterface;
use App\Service\Tool\Policy\NullGroupPolicyProvider;
use App\Service\Tool\Policy\PolicyContext;
use App\Service\Tool\Policy\PolicyOutcome;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolSource;
use App\Service\Tool\ToolsConfig;
use PHPUnit\Framework\TestCase;

final class ApprovalPolicyTest extends TestCase
{
    public function testDefaultsReadAutoWriteApproveDestructiveBlock(): void
    {
        $policy = $this->policy();
        $this->assertSame(PolicyOutcome::Auto, $policy->decide($this->tool(SideEffect::Read), 1, PolicyContext::Interactive));
        $this->assertSame(PolicyOutcome::Approve, $policy->decide($this->tool(SideEffect::Write), 1, PolicyContext::Interactive));
        $this->assertSame(PolicyOutcome::Block, $policy->decide($this->tool(SideEffect::Destructive), 1, PolicyContext::Interactive));
    }

    public function testAllowWriteOffBlocksRegardlessOfOverrides(): void
    {
        $policy = $this->policy();
        $tool = new ToolDescriptor(
            'mcp:1:create',
            'Create',
            '',
            [],
            SideEffect::Write,
            ToolSource::Mcp,
            1,
            meta: ['allowWrite' => false],
        );
        $this->assertSame(
            PolicyOutcome::Block,
            $policy->decide(
                $tool,
                1,
                PolicyContext::Unattended,
                ['policy' => ['create' => 'auto', 'write' => 'auto']],
                true,
                'assistant:9',
            ),
        );
    }

    public function testAllowUnattendedTurnsApproveIntoAutoForWriteOnly(): void
    {
        $policy = $this->policy();
        $write = $this->tool(SideEffect::Write);
        $this->assertSame(PolicyOutcome::Auto, $policy->decide($write, 1, PolicyContext::Unattended, null, true));
        $destructive = $this->tool(SideEffect::Destructive);
        $this->assertSame(PolicyOutcome::Block, $policy->decide($destructive, 1, PolicyContext::Unattended, null, true));
    }

    public function testOwnArtefactDocumentToolsAutoForOwner(): void
    {
        $policy = $this->policy();
        $tool = new ToolDescriptor(
            'doc_edit',
            'Edit',
            '',
            [],
            SideEffect::Write,
            ToolSource::Document,
            7,
            ToolDescriptor::POLICY_OWN_ARTEFACT,
        );
        $this->assertSame(PolicyOutcome::Auto, $policy->decide($tool, 7, PolicyContext::Interactive));
        $this->assertSame(PolicyOutcome::Approve, $policy->decide($tool, 8, PolicyContext::Interactive));
    }

    public function testUserOverrideNeverLoosensBlock(): void
    {
        $config = $this->createMock(ToolsConfig::class);
        $config->method('defaultOutcome')->willReturn(PolicyOutcome::Block);
        $config->method('alwaysAllowTools')->willReturn(['blocked_tool']);
        $policy = new ApprovalPolicy($config, new NullGroupPolicyProvider(), $this->assistant());
        $tool = new ToolDescriptor('blocked_tool', 'X', '', [], SideEffect::Destructive, ToolSource::Custom, 1);
        $this->assertSame(PolicyOutcome::Block, $policy->decide($tool, 1, PolicyContext::Interactive, null, false, 'assistant:1'));
    }

    public function testCustomDestructiveBlocksByDefault(): void
    {
        $policy = $this->policy();
        $tool = new ToolDescriptor('custom:delete_row', 'Delete', '', [], SideEffect::Destructive, ToolSource::Custom, 1);
        $this->assertSame(PolicyOutcome::Block, $policy->decide($tool, 1, PolicyContext::Interactive));
    }

    public function testMostRestrictiveWins(): void
    {
        $group = $this->createMock(GroupPolicyProviderInterface::class);
        $group->method('outcomeFor')->willReturn(PolicyOutcome::Approve);
        $assistant = $this->createMock(AssistantPolicyProviderInterface::class);
        $assistant->method('outcomeFor')->willReturn(PolicyOutcome::Auto);
        $config = $this->createMock(ToolsConfig::class);
        $config->method('defaultOutcome')->willReturn(PolicyOutcome::Auto);
        $config->method('alwaysAllowTools')->willReturn([]);
        $policy = new ApprovalPolicy($config, $group, $assistant);
        $this->assertSame(PolicyOutcome::Approve, $policy->decide($this->tool(SideEffect::Read), 1, PolicyContext::Interactive));
    }

    private function policy(): ApprovalPolicy
    {
        $config = $this->createMock(ToolsConfig::class);
        $config->method('defaultOutcome')->willReturnCallback(
            static fn (SideEffect $side): PolicyOutcome => match ($side) {
                SideEffect::Read => PolicyOutcome::Auto,
                SideEffect::Write => PolicyOutcome::Approve,
                SideEffect::Destructive => PolicyOutcome::Block,
            },
        );
        $config->method('alwaysAllowTools')->willReturn([]);

        return new ApprovalPolicy($config, new NullGroupPolicyProvider(), $this->assistant());
    }

    private function assistant(): AssistantPolicyProviderInterface
    {
        $assistant = $this->createMock(AssistantPolicyProviderInterface::class);
        $assistant->method('outcomeFor')->willReturn(null);

        return $assistant;
    }

    private function tool(SideEffect $side): ToolDescriptor
    {
        return new ToolDescriptor('web_search', 'Web search', '', [], $side, ToolSource::Builtin, 0);
    }
}
