<?php

declare(strict_types=1);

namespace App\Service\Tool;

use App\Entity\Approval;
use App\Entity\User;
use App\Service\Mcp\Notion\NotionWriteGuard;
use App\Service\Tool\Exception\ToolNotRegisteredException;
use App\Service\Tool\Policy\ApprovalPolicy;
use App\Service\Tool\Policy\McpToolMeta;
use App\Service\Tool\Policy\PolicyContext;
use App\Service\Tool\Policy\PolicyOutcome;

/**
 * Single decision point the four execution loops call before running a tool.
 */
final readonly class ToolExecutionGate
{
    /**
     * Refusal when an authored Saved Task step pins `params.approval = block`.
     * Applies even while the approvals flag is off — a step can only tighten.
     */
    public const NODE_BLOCK_REFUSAL = 'I cannot do that. This step is set to never run this tool.';

    public const CANNOT_ASK_REFUSAL = 'I cannot do that here. This tool is set to ask first, and this step cannot ask. Run it in a chat turn that can show an approval card, or change the tool to Auto in the connection permissions.';

    public function __construct(
        private ToolsConfig $toolsConfig,
        private ToolRegistry $registry,
        private ApprovalPolicy $approvalPolicy,
        private ApprovalService $approvalService,
    ) {
    }

    /**
     * Whether {@see inspect} can hand back a pending approval at all. While the
     * approvals flag is off the gate answers Auto for everything but a node
     * block, so a caller that MUST have a human in the loop has to refuse
     * instead of calling inspect().
     */
    public function approvalsEnabled(int $userId): bool
    {
        return $this->toolsConfig->isApprovalsEnabled($userId);
    }

    /**
     * @param array<string, mixed>      $args
     * @param array<string, mixed>|null $assistantTools
     * @param bool                      $requireApproval when true an Auto outcome is lifted to Approve — the
     *                                                   caller needs a human in the loop regardless of
     *                                                   always-allow rules or `allowUnattended`; Block still wins.
     *                                                   Meaningless while approvals are off: check
     *                                                   {@see approvalsEnabled} first and refuse.
     *
     * @return array{outcome: PolicyOutcome, descriptor: ToolDescriptor, approval: Approval|null, refusal: string|null}
     */
    public function inspect(
        int $userId,
        string $toolName,
        array $args,
        User $actor,
        PolicyContext $context,
        string $requestedBy,
        ?array $assistantTools = null,
        bool $allowUnattended = false,
        ?string $assistantKey = null,
        ?string $nodeApproval = null,
        bool $requireApproval = false,
        ?array $resolvedRequest = null,
        bool $alreadyApproved = false,
        ?string $forcedRefusal = null,
        bool $canPause = true,
    ): array {
        $descriptor = $this->registry->get($userId, $toolName);
        if (null === $descriptor && null !== $forcedRefusal && '' !== $forcedRefusal) {
            $descriptor = new ToolDescriptor($toolName, $toolName, '', [], SideEffect::Write, ToolSource::Mcp, $userId);
        }
        if (null === $descriptor) {
            throw new ToolNotRegisteredException($toolName);
        }

        if (null !== $forcedRefusal && '' !== $forcedRefusal) {
            return $this->refused($descriptor, $forcedRefusal);
        }

        $nodeOverride = PolicyOutcome::tryFrom((string) $nodeApproval);

        // An MCP connection's Block holds in every path, also with approvals
        // off and for a call a person approved earlier.
        $standing = $this->mcpStandingRefusal($descriptor, $args, $userId, $alreadyApproved);
        if (null !== $standing) {
            return $this->refused($descriptor, $standing);
        }

        if ($alreadyApproved || !$this->toolsConfig->isApprovalsEnabled($userId)) {
            $blocked = PolicyOutcome::Block === $nodeOverride;

            return [
                'outcome' => $blocked ? PolicyOutcome::Block : PolicyOutcome::Auto,
                'descriptor' => $descriptor,
                'approval' => null,
                'refusal' => $blocked ? self::NODE_BLOCK_REFUSAL : null,
            ];
        }

        $deletesContent = $this->deletesContent($descriptor, $args);
        $descriptor = $this->classedForCall($descriptor, $deletesContent);
        $outcome = $this->approvalPolicy->decide(
            $descriptor,
            $userId,
            $context,
            $assistantTools,
            $allowUnattended,
            $assistantKey,
            $nodeOverride,
            $deletesContent,
        );
        if ($requireApproval && PolicyOutcome::Auto === $outcome) {
            $outcome = PolicyOutcome::Approve;
        }
        // A chat plan cannot be resumed after a pause. Opening an approval
        // there would run the tool in the chat continuation and leave the step
        // waiting. An MCP tool the person wants to confirm is refused instead
        // of running unasked.
        if (!$canPause && PolicyOutcome::Approve === $outcome) {
            if (null !== $this->approvalPolicy->connectionOutcome($descriptor)) {
                return $this->refused($descriptor, self::CANNOT_ASK_REFUSAL);
            }
            $outcome = PolicyOutcome::Auto;
        }

        if (PolicyOutcome::Block === $outcome) {
            return $this->refused($descriptor, 'I cannot do that. An administrator has turned this off.');
        }

        if (PolicyOutcome::Approve === $outcome) {
            $approval = $this->approvalService->request($descriptor, $args, $requestedBy, $actor, $resolvedRequest);

            return [
                'outcome' => $outcome,
                'descriptor' => $descriptor,
                'approval' => $approval,
                'refusal' => null,
            ];
        }

        return [
            'outcome' => $outcome,
            'descriptor' => $descriptor,
            'approval' => null,
            'refusal' => null,
        ];
    }

    /**
     * @return array{outcome: PolicyOutcome, descriptor: ToolDescriptor, approval: Approval|null, refusal: string|null}
     */
    private function refused(ToolDescriptor $descriptor, string $refusal): array
    {
        return [
            'outcome' => PolicyOutcome::Block,
            'descriptor' => $descriptor,
            'approval' => null,
            'refusal' => $refusal,
        ];
    }

    /**
     * A call that deletes content is a destructive call, whatever the tool's
     * class: policy, approval and audit see it as such, and its approval
     * offers no "always allow".
     */
    private function classedForCall(ToolDescriptor $descriptor, bool $deletesContent): ToolDescriptor
    {
        if (!$deletesContent || SideEffect::Destructive === $descriptor->sideEffect) {
            return $descriptor;
        }

        return new ToolDescriptor(
            $descriptor->name,
            $descriptor->title,
            $descriptor->description,
            $descriptor->inputSchema,
            SideEffect::Destructive,
            $descriptor->source,
            $descriptor->ownerId,
            $descriptor->policyException,
            $descriptor->meta,
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    private function mcpStandingRefusal(ToolDescriptor $descriptor, array $args, int $userId, bool $alreadyApproved): ?string
    {
        $connection = $this->approvalPolicy->connectionOutcome($descriptor);
        if (null === $connection) {
            return null;
        }
        $label = is_string($descriptor->meta['tool'] ?? null) ? $descriptor->meta['tool'] : $descriptor->title;
        if (PolicyOutcome::Block === $connection) {
            return sprintf('I cannot do that. The tool "%s" is blocked in the permissions of this connection.', $label);
        }
        $asks = PolicyOutcome::Approve === $connection || $this->deletesContent($descriptor, $args);
        if ($asks && !$alreadyApproved && !$this->toolsConfig->isApprovalsEnabled($userId)) {
            return sprintf('I cannot do that. The tool "%s" needs your approval and approvals are turned off on this instance.', $label);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function deletesContent(ToolDescriptor $descriptor, array $args): bool
    {
        if (ToolSource::Mcp !== $descriptor->source) {
            return false;
        }
        $url = $descriptor->meta[McpToolMeta::SERVER_URL] ?? null;
        $tool = $descriptor->meta['tool'] ?? null;
        if (!is_string($url) || !is_string($tool)) {
            return false;
        }

        return null !== NotionWriteGuard::contentDeletion($url, $tool, $args);
    }
}
