<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Tool\Exception\ToolNotRegisteredException;
use App\Service\Tool\Policy\PolicyContext;
use App\Service\Tool\Policy\PolicyOutcome;
use App\Service\Tool\ToolExecutionGate;

/**
 * Applies {@see ToolExecutionGate} to a DAG step. Email/folder/calendar
 * runners share this so a write-class skill cannot skip the same policy a
 * `tool_call` step already honours (issue #1883).
 */
final readonly class StepApprovalGate
{
    public function __construct(
        private ToolExecutionGate $executionGate,
        private UserRepository $users,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function consult(NodeContext $context, TaskNode $node, string $toolName, array $arguments): ?NodeResult
    {
        $override = is_string($node->params['approval'] ?? null) ? $node->params['approval'] : null;
        if ($context->isApproved($node->id)) {
            return null;
        }

        $userId = (int) ($context->userId ?? $context->message->getUserId());
        if ($userId <= 0) {
            return NodeResult::failed('This account cannot run Saved Tasks');
        }
        $actor = $this->users->find($userId);
        if (!$actor instanceof User) {
            return NodeResult::failed('This account cannot run Saved Tasks');
        }

        $runId = is_numeric($context->options['saved_task_run_id'] ?? null) ? (int) $context->options['saved_task_run_id'] : 0;
        $unattended = true === ($context->options['saved_task'] ?? false);
        $requestedBy = $runId > 0
            ? sprintf('task_run:%d:%s', $runId, $node->id)
            : 'chat:'.(int) $context->message->getId();

        try {
            $decision = $this->executionGate->inspect(
                $userId,
                $toolName,
                $arguments,
                $actor,
                $unattended ? PolicyContext::Unattended : PolicyContext::Interactive,
                $requestedBy,
                null,
                true === ($context->options['allow_unattended'] ?? false),
                null,
                $override,
            );
        } catch (ToolNotRegisteredException $e) {
            return NodeResult::failed($e->getMessage());
        }
        if (PolicyOutcome::Block === $decision['outcome']) {
            return NodeResult::failed((string) $decision['refusal']);
        }
        if (PolicyOutcome::Approve === $decision['outcome'] && null !== $decision['approval']) {
            $approval = $decision['approval'];

            return NodeResult::waitingApproval((int) $approval->getId(), $arguments, [
                'tool' => $approval->getTool(),
                'preview' => $approval->getPreview(),
                'expires_at' => $approval->getExpiresAt(),
                'side_effect' => $approval->getSideEffect(),
            ]);
        }

        return null;
    }
}
