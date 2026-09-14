<?php

declare(strict_types=1);

namespace App\Service\Tool;

use App\Entity\Approval;
use App\Entity\User;
use App\Service\Tool\Exception\ToolNotRegisteredException;
use App\Service\Tool\Policy\ApprovalPolicy;
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
    ): array {
        $descriptor = $this->registry->get($userId, $toolName);
        if (null === $descriptor) {
            throw new ToolNotRegisteredException($toolName);
        }

        $nodeOverride = PolicyOutcome::tryFrom((string) $nodeApproval);

        if (!$this->toolsConfig->isApprovalsEnabled($userId)) {
            return [
                'outcome' => PolicyOutcome::Block === $nodeOverride ? PolicyOutcome::Block : PolicyOutcome::Auto,
                'descriptor' => $descriptor,
                'approval' => null,
                'refusal' => PolicyOutcome::Block === $nodeOverride ? self::NODE_BLOCK_REFUSAL : null,
            ];
        }

        $outcome = $this->approvalPolicy->decide(
            $descriptor,
            $userId,
            $context,
            $assistantTools,
            $allowUnattended,
            $assistantKey,
            $nodeOverride,
        );
        if ($requireApproval && PolicyOutcome::Auto === $outcome) {
            $outcome = PolicyOutcome::Approve;
        }

        if (PolicyOutcome::Block === $outcome) {
            return [
                'outcome' => $outcome,
                'descriptor' => $descriptor,
                'approval' => null,
                'refusal' => 'I cannot do that. An administrator has turned this off.',
            ];
        }

        if (PolicyOutcome::Approve === $outcome) {
            $approval = $this->approvalService->request($descriptor, $args, $requestedBy, $actor);

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
}
