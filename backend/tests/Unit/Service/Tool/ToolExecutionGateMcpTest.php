<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool;

use App\Entity\Approval;
use App\Entity\User;
use App\Service\Tool\ApprovalService;
use App\Service\Tool\Policy\ApprovalPolicy;
use App\Service\Tool\Policy\AssistantPolicyProviderInterface;
use App\Service\Tool\Policy\McpToolMeta;
use App\Service\Tool\Policy\NullGroupPolicyProvider;
use App\Service\Tool\Policy\PolicyContext;
use App\Service\Tool\Policy\PolicyOutcome;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolExecutionGate;
use App\Service\Tool\ToolRegistry;
use App\Service\Tool\ToolsConfig;
use App\Service\Tool\ToolSource;
use PHPUnit\Framework\TestCase;

/**
 * What the gate does with the connection's choice for an MCP tool.
 */
final class ToolExecutionGateMcpTest extends TestCase
{
    private const NOTION = 'https://mcp.notion.com/mcp';

    public function testABlockedToolIsRefusedAndNamesTheConnectionPermissions(): void
    {
        $decision = $this->inspect('block', approvalsOn: true);

        self::assertSame(PolicyOutcome::Block, $decision['outcome']);
        self::assertStringContainsString('blocked in the permissions of this connection', (string) $decision['refusal']);
    }

    public function testABlockHoldsWithApprovalsOffAndForACallThatWasApprovedEarlier(): void
    {
        self::assertSame(PolicyOutcome::Block, $this->inspect('block', approvalsOn: false)['outcome']);
        self::assertSame(PolicyOutcome::Block, $this->inspect('block', approvalsOn: true, alreadyApproved: true)['outcome']);
        self::assertSame(PolicyOutcome::Block, $this->inspect('block', approvalsOn: false, alreadyApproved: true)['outcome']);
    }

    public function testApproveOpensACardAndAutoRunsFreely(): void
    {
        $approval = $this->createStub(Approval::class);
        $approvals = $this->createMock(ApprovalService::class);
        $approvals->expects(self::once())->method('request')->willReturn($approval);

        $asked = $this->inspect('approve', approvalsOn: true, approvals: $approvals);
        self::assertSame(PolicyOutcome::Approve, $asked['outcome']);
        self::assertSame($approval, $asked['approval']);
        self::assertSame(PolicyOutcome::Auto, $this->inspect('auto', approvalsOn: true)['outcome']);
    }

    public function testWithApprovalsOffAnApproveIsRefusedAndAutoStillRuns(): void
    {
        $approvals = $this->createMock(ApprovalService::class);
        $approvals->expects(self::never())->method('request');

        $asked = $this->inspect('approve', approvalsOn: false, approvals: $approvals);
        self::assertSame(PolicyOutcome::Block, $asked['outcome']);
        self::assertStringContainsString('approvals are turned off', (string) $asked['refusal']);
        self::assertSame(PolicyOutcome::Auto, $this->inspect('auto', approvalsOn: false)['outcome']);
    }

    public function testAnApprovedCallRunsEvenWithApprovalsOff(): void
    {
        self::assertSame(PolicyOutcome::Auto, $this->inspect('approve', approvalsOn: false, alreadyApproved: true)['outcome']);
    }

    public function testAStepThatCannotAskRefusesAnApproveInsteadOfRunningIt(): void
    {
        $approvals = $this->createMock(ApprovalService::class);
        $approvals->expects(self::never())->method('request');

        $decision = $this->inspect('approve', approvalsOn: true, approvals: $approvals, canPause: false);

        self::assertSame(PolicyOutcome::Block, $decision['outcome']);
        self::assertSame(ToolExecutionGate::CANNOT_ASK_REFUSAL, $decision['refusal']);
        self::assertSame(PolicyOutcome::Auto, $this->inspect('auto', approvalsOn: true, canPause: false)['outcome']);
    }

    public function testArgumentsThatDeleteContentAskEvenForAnAutoTool(): void
    {
        $approval = $this->createStub(Approval::class);
        $approvals = $this->createMock(ApprovalService::class);
        $approvals->expects(self::once())->method('request')->willReturn($approval);

        $decision = $this->inspect('auto', approvalsOn: true, approvals: $approvals, tool: 'notion-update-page', args: ['command' => 'replace_content']);
        self::assertSame(PolicyOutcome::Approve, $decision['outcome']);

        self::assertSame(
            PolicyOutcome::Auto,
            $this->inspect('auto', approvalsOn: true, tool: 'notion-update-page', args: ['command' => 'update_content'])['outcome'],
        );
        self::assertSame(
            PolicyOutcome::Block,
            $this->inspect('block', approvalsOn: true, tool: 'notion-update-page', args: ['command' => 'replace_content'])['outcome'],
        );
        self::assertSame(
            PolicyOutcome::Block,
            $this->inspect('auto', approvalsOn: false, tool: 'notion-update-page', args: ['allow_deleting_content' => true])['outcome'],
            'cannot ask while approvals are off',
        );
    }

    public function testUnattendedAutoWriteAsksUnlessTheStepAllowsIt(): void
    {
        $approvals = $this->approvals();

        self::assertSame(PolicyOutcome::Approve, $this->inspect('auto', approvalsOn: true, approvals: $approvals, context: PolicyContext::Unattended)['outcome']);
        self::assertSame(PolicyOutcome::Auto, $this->inspect('auto', approvalsOn: true, context: PolicyContext::Unattended, allowUnattended: true)['outcome']);
    }

    private function approvals(): ApprovalService
    {
        $approvals = $this->createStub(ApprovalService::class);
        $approvals->method('request')->willReturn($this->createStub(Approval::class));

        return $approvals;
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array{outcome: PolicyOutcome, descriptor: ToolDescriptor, approval: Approval|null, refusal: string|null}
     */
    private function inspect(
        string $connection,
        bool $approvalsOn,
        bool $alreadyApproved = false,
        ?ApprovalService $approvals = null,
        bool $canPause = true,
        string $tool = 'search',
        array $args = [],
        PolicyContext $context = PolicyContext::Interactive,
        bool $allowUnattended = false,
    ): array {
        $config = $this->createStub(ToolsConfig::class);
        $config->method('isApprovalsEnabled')->willReturn($approvalsOn);
        $config->method('defaultOutcome')->willReturn(PolicyOutcome::Block);
        $config->method('alwaysAllowTools')->willReturn([]);

        $descriptor = new ToolDescriptor('mcp:1:'.$tool, $tool, '', [], SideEffect::Write, ToolSource::Mcp, 7, meta: [
            'serverId' => 1,
            'tool' => $tool,
            McpToolMeta::CONNECTION_OUTCOME => $connection,
            McpToolMeta::SERVER_URL => self::NOTION,
        ]);
        $registry = $this->createStub(ToolRegistry::class);
        $registry->method('get')->willReturn($descriptor);

        $assistant = $this->createStub(AssistantPolicyProviderInterface::class);
        $assistant->method('outcomeFor')->willReturn(null);
        $policy = new ApprovalPolicy($config, new NullGroupPolicyProvider(), $assistant);
        $approvals ??= $this->approvals();

        return (new ToolExecutionGate($config, $registry, $policy, $approvals))->inspect(
            7,
            'mcp:1:'.$tool,
            $args,
            $this->createStub(User::class),
            $context,
            'chat:11',
            allowUnattended: $allowUnattended,
            alreadyApproved: $alreadyApproved,
            canPause: $canPause,
        );
    }
}
