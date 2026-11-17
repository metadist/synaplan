<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Policy;

use App\Service\Tool\Policy\ApprovalPolicy;
use App\Service\Tool\Policy\AssistantPolicyProviderInterface;
use App\Service\Tool\Policy\GroupPolicyProviderInterface;
use App\Service\Tool\Policy\McpToolMeta;
use App\Service\Tool\Policy\NullGroupPolicyProvider;
use App\Service\Tool\Policy\PolicyContext;
use App\Service\Tool\Policy\PolicyOutcome;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolsConfig;
use App\Service\Tool\ToolSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Resolution of one MCP tool call (plan 20261117-mcp-tool-permissions, §2.1).
 */
final class ApprovalPolicyMcpTest extends TestCase
{
    public function testTheConnectionChoiceReplacesTheInstanceDefault(): void
    {
        // The instance default would block every destructive tool.
        $policy = $this->policy(instanceDefault: PolicyOutcome::Block);

        self::assertSame(PolicyOutcome::Auto, $policy->decide($this->tool(SideEffect::Destructive, 'auto'), 1, PolicyContext::Interactive));
        self::assertSame(PolicyOutcome::Approve, $policy->decide($this->tool(SideEffect::Write, 'approve'), 1, PolicyContext::Interactive));
        self::assertSame(PolicyOutcome::Block, $policy->decide($this->tool(SideEffect::Read, 'block'), 1, PolicyContext::Interactive));
    }

    public function testAToolWithoutAConnectionChoiceKeepsTheInstanceDefault(): void
    {
        $tool = new ToolDescriptor('mcp:1:x', 'x', '', [], SideEffect::Destructive, ToolSource::Mcp, 1);

        self::assertSame(PolicyOutcome::Block, $this->policy(instanceDefault: PolicyOutcome::Block)->decide($tool, 1, PolicyContext::Interactive));
    }

    #[DataProvider('restrictions')]
    public function testGroupAssistantAndNodeCanOnlyTighten(string $connection, PolicyOutcome $restriction, PolicyOutcome $expected): void
    {
        $group = $this->createMock(GroupPolicyProviderInterface::class);
        $group->method('outcomeFor')->willReturn($restriction);
        $policy = new ApprovalPolicy($this->config(PolicyOutcome::Auto), $group, $this->assistant(null));

        self::assertSame($expected, $policy->decide($this->tool(SideEffect::Write, $connection), 1, PolicyContext::Interactive));

        $assistant = $this->createMock(AssistantPolicyProviderInterface::class);
        $assistant->method('outcomeFor')->willReturn($restriction);
        $byAssistant = new ApprovalPolicy($this->config(PolicyOutcome::Auto), new NullGroupPolicyProvider(), $assistant);
        self::assertSame($expected, $byAssistant->decide($this->tool(SideEffect::Write, $connection), 1, PolicyContext::Interactive));

        self::assertSame($expected, $this->policy()->decide($this->tool(SideEffect::Write, $connection), 1, PolicyContext::Interactive, nodeOverride: $restriction));
    }

    /**
     * @return iterable<string, array{string, PolicyOutcome, PolicyOutcome}>
     */
    public static function restrictions(): iterable
    {
        yield 'auto + approve' => ['auto', PolicyOutcome::Approve, PolicyOutcome::Approve];
        yield 'auto + block' => ['auto', PolicyOutcome::Block, PolicyOutcome::Block];
        yield 'approve + auto' => ['approve', PolicyOutcome::Auto, PolicyOutcome::Approve];
        yield 'block + auto' => ['block', PolicyOutcome::Auto, PolicyOutcome::Block];
        yield 'block + approve' => ['block', PolicyOutcome::Approve, PolicyOutcome::Block];
    }

    public function testArgumentsThatDeleteContentAskEvenWhenTheToolIsAuto(): void
    {
        $tool = $this->tool(SideEffect::Write, 'auto');

        self::assertSame(PolicyOutcome::Approve, $this->policy()->decide($tool, 1, PolicyContext::Interactive, escalateToApproval: true));
        self::assertSame(PolicyOutcome::Block, $this->policy()->decide($this->tool(SideEffect::Write, 'block'), 1, PolicyContext::Interactive, escalateToApproval: true));
    }

    public function testUnattendedRunsAskForWriteAndDestructiveButNotForRead(): void
    {
        $policy = $this->policy();

        self::assertSame(PolicyOutcome::Approve, $policy->decide($this->tool(SideEffect::Write, 'auto'), 1, PolicyContext::Unattended));
        self::assertSame(PolicyOutcome::Approve, $policy->decide($this->tool(SideEffect::Destructive, 'auto'), 1, PolicyContext::Unattended));
        self::assertSame(PolicyOutcome::Auto, $policy->decide($this->tool(SideEffect::Read, 'auto'), 1, PolicyContext::Unattended));
        self::assertSame(PolicyOutcome::Auto, $policy->decide($this->tool(SideEffect::Write, 'auto'), 1, PolicyContext::Unattended, allowUnattended: true));
    }

    public function testAnAutoSetInChatIsStillAutoInChat(): void
    {
        self::assertSame(PolicyOutcome::Auto, $this->policy()->decide($this->tool(SideEffect::Write, 'auto'), 1, PolicyContext::Interactive));
    }

    #[DataProvider('everySideEffect')]
    public function testNothingReachesAutoFromABlock(SideEffect $class): void
    {
        $config = $this->config(PolicyOutcome::Approve, ['mcp:1:tool', 'mcp__1__tool', 'tool']);
        $policy = new ApprovalPolicy($config, new NullGroupPolicyProvider(), $this->assistant(PolicyOutcome::Auto));
        $tool = $this->tool($class, 'block');

        foreach ([PolicyContext::Interactive, PolicyContext::Unattended] as $context) {
            self::assertSame(PolicyOutcome::Block, $policy->decide($tool, 1, $context, ['policy' => ['tool' => 'auto']], true, 'assistant:9'), 'always-allow and allow_unattended');
            self::assertSame(PolicyOutcome::Block, $policy->decide($tool, 1, $context, nodeOverride: PolicyOutcome::Auto), 'node override');
        }
    }

    #[DataProvider('everySideEffect')]
    public function testAnExplicitApproveIsNotLoosenedByAlwaysAllowOrAllowUnattended(SideEffect $class): void
    {
        $config = $this->config(PolicyOutcome::Auto, ['mcp:1:tool', 'tool']);
        $policy = new ApprovalPolicy($config, new NullGroupPolicyProvider(), $this->assistant(null));
        $tool = $this->tool($class, 'approve');

        self::assertSame(PolicyOutcome::Approve, $policy->decide($tool, 1, PolicyContext::Interactive, null, false, 'assistant:1'));
        self::assertSame(PolicyOutcome::Approve, $policy->decide($tool, 1, PolicyContext::Unattended, null, true, 'assistant:1'));
    }

    /**
     * @return iterable<string, array{SideEffect}>
     */
    public static function everySideEffect(): iterable
    {
        yield 'read' => [SideEffect::Read];
        yield 'write' => [SideEffect::Write];
        yield 'destructive' => [SideEffect::Destructive];
    }

    private function tool(SideEffect $class, string $connectionOutcome): ToolDescriptor
    {
        return new ToolDescriptor('mcp:1:tool', 'tool', '', [], $class, ToolSource::Mcp, 1, meta: [
            'serverId' => 1,
            'tool' => 'tool',
            McpToolMeta::CONNECTION_OUTCOME => $connectionOutcome,
        ]);
    }

    private function policy(PolicyOutcome $instanceDefault = PolicyOutcome::Approve): ApprovalPolicy
    {
        return new ApprovalPolicy($this->config($instanceDefault), new NullGroupPolicyProvider(), $this->assistant(null));
    }

    /**
     * @param list<string> $alwaysAllow
     */
    private function config(PolicyOutcome $default, array $alwaysAllow = []): ToolsConfig
    {
        $config = $this->createMock(ToolsConfig::class);
        $config->method('defaultOutcome')->willReturn($default);
        $config->method('alwaysAllowTools')->willReturn($alwaysAllow);

        return $config;
    }

    private function assistant(?PolicyOutcome $outcome): AssistantPolicyProviderInterface
    {
        $assistant = $this->createMock(AssistantPolicyProviderInterface::class);
        $assistant->method('outcomeFor')->willReturn($outcome);

        return $assistant;
    }
}
