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
    public function __construct(
        private ToolsConfig $toolsConfig,
        private ToolRegistry $registry,
        private ApprovalPolicy $approvalPolicy,
        private ApprovalService $approvalService,
    ) {
    }

    /**
     * @param array<string, mixed>      $args
     * @param array<string, mixed>|null $assistantTools
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
                'refusal' => PolicyOutcome::Block === $nodeOverride
                    ? 'I cannot do that. This step is set to always ask — and it is blocked.'
                    : null,
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
