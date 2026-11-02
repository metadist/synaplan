<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Entity\ComputeRun;
use App\Entity\User;
use App\Repository\ComputeRunRepository;
use App\Service\Compute\ComputeConfig;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Tool\Exception\ToolNotRegisteredException;
use App\Service\Tool\Execution\GateMode;
use App\Service\Tool\Execution\GateRequest;
use App\Service\Tool\Execution\ToolCaller;
use App\Service\Tool\Execution\ToolChannel;
use App\Service\Tool\Execution\ToolInvocation;
use App\Service\Tool\Execution\ToolInvoker;
use App\Service\Tool\Execution\ToolInvokerSlot;
use App\Service\Tool\Execution\ToolOutcomeKind;
use App\Service\Tool\Policy\PolicyContext;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolExecutionGate;
use App\Service\Tool\ToolSource;

/**
 * The file-work approval decision. The sidecar stays in the runner;
 * this only asks the invoker and, when a person must confirm, writes
 * the queued audit row.
 */
final readonly class CodeRunApproval
{
    public function __construct(
        private ComputeConfig $computeConfig,
        private ComputeRunRepository $runs,
        private ToolInvokerSlot $toolInvoker,
        private ?ToolExecutionGate $executionGate = null,
    ) {
    }

    /**
     * @param list<int>    $inputFileIds
     * @param list<string> $egressHosts
     *
     * @return array{
     *     outcome: string,
     *     status: string,
     *     exit_code: ?int,
     *     stdout: string,
     *     stderr: string,
     *     artefacts: list<array{file_id: int, name: string, mime: string, size: int}>,
     *     error: ?string,
     *     approval_id: ?int,
     *     compute_run_id: ?string
     * }|string|null a refusal sentence, a waiting row, or null to run
     */
    public function decide(
        User $user,
        string $language,
        string $code,
        array $inputFileIds,
        ?int $timeoutSec,
        string $invokedVia,
        PolicyContext $policyContext,
        bool $allowUnattended,
        ?int $savedTaskRunId,
        ?int $messageId,
        ?TaskNode $node,
        bool $forceApproveForEgress = false,
        array $egressHosts = [],
    ): array|string|null {
        $userId = (int) $user->getId();
        $args = ['language' => $language];
        if ([] !== $egressHosts) {
            // A scalar so the approval preview shows which websites the run may reach.
            $args['websites'] = implode(', ', $egressHosts);
        }
        $args += [
            'code' => $code,
            'input_file_ids' => $inputFileIds,
            'timeout_sec' => $timeoutSec,
        ];
        $requestedBy = null !== $savedTaskRunId && null !== $node
            ? sprintf('task_run:%d:%s', $savedTaskRunId, $node->id)
            : 'chat:'.(int) ($messageId ?? 0);
        $override = is_string($node?->params['approval'] ?? null) ? $node->params['approval'] : null;

        try {
            $outcome = $this->tools()->invoke(
                new ToolDescriptor(
                    Capability::CodeRun->value,
                    Capability::CodeRun->value,
                    '',
                    [],
                    SideEffect::Write,
                    ToolSource::Compute,
                    $userId,
                ),
                new ToolInvocation(
                    Capability::CodeRun->value,
                    $args,
                    new ToolCaller($userId, ToolChannel::CODE_RUN, unattended: PolicyContext::Unattended === $policyContext),
                    null === $this->executionGate ? GateMode::AsTodayNever : GateMode::AsTodayAlways,
                    decision: new GateRequest(
                        $user,
                        $policyContext,
                        $requestedBy,
                        allowUnattended: $allowUnattended,
                        nodeApproval: $override,
                        requireApproval: $forceApproveForEgress,
                    ),
                    decideOnly: true,
                    user: $user,
                ),
            );
        } catch (ToolNotRegisteredException) {
            return null;
        }

        if (ToolOutcomeKind::Refused === $outcome->kind) {
            return $outcome->resultText;
        }
        if (ToolOutcomeKind::NeedsApproval === $outcome->kind && null !== $outcome->approval) {
            return $this->waiting($userId, $language, $timeoutSec, $invokedVia, $savedTaskRunId, $messageId, (int) $outcome->approval->getId());
        }

        return null;
    }

    /**
     * @return array{
     *     outcome: string,
     *     status: string,
     *     exit_code: ?int,
     *     stdout: string,
     *     stderr: string,
     *     artefacts: list<array{file_id: int, name: string, mime: string, size: int}>,
     *     error: ?string,
     *     approval_id: ?int,
     *     compute_run_id: ?string
     * }
     */
    private function waiting(int $userId, string $language, ?int $timeoutSec, string $invokedVia, ?int $savedTaskRunId, ?int $messageId, int $approvalId): array
    {
        $image = 'node' === $language ? 'node' : 'python';
        $limits = $this->computeConfig->clampLimits(null !== $timeoutSec ? ['timeoutSec' => $timeoutSec] : []);
        $audit = new ComputeRun($userId, 'q'.bin2hex(random_bytes(12)), $invokedVia, $image, $image, $limits);
        $audit->setApprovalId($approvalId);
        $audit->setMessageId($messageId);
        $audit->setSavedTaskRunId($savedTaskRunId);
        $this->runs->save($audit);

        return [
            'outcome' => 'waiting_approval',
            'status' => ComputeRun::STATUS_QUEUED,
            'exit_code' => null,
            'stdout' => '',
            'stderr' => '',
            'artefacts' => [],
            'error' => null,
            'approval_id' => $approvalId,
            'compute_run_id' => $audit->getRunId(),
        ];
    }

    private function tools(): ToolInvoker
    {
        return $this->toolInvoker->get() ?? new ToolInvoker([], gate: $this->executionGate);
    }
}
