<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution;

use App\Service\Multitask\Plan\TaskPlan;

/**
 * Turns the executed node results into one final response: user-facing text +
 * the collected file attachments, plus per-node status for persistence.
 *
 * The reply node (`plan.replyNode`) provides the user-facing text and files. For
 * a `compose_reply` node its runner has already gathered text + attachments from
 * its dependencies. On partial failure the assembler degrades to a best-effort
 * answer (any successful text) with a short error note rather than crashing.
 */
final class ResultAssembler
{
    /**
     * @return array{
     *     content: string,
     *     files: list<array<string, mixed>>,
     *     metadata: array<string, mixed>,
     *     node_statuses: array<string, string>,
     *     partial_failure: bool,
     *     all_failed: bool
     * }
     */
    public function assemble(TaskPlan $plan, NodeContext $context): array
    {
        $statuses = [];
        $successCount = 0;
        $failureCount = 0;
        foreach ($plan->nodes as $node) {
            $result = $context->getResult($node->id);
            $status = null !== $result ? $result->status : NodeStatus::Skipped;
            $statuses[$node->id] = $status->value;
            if (NodeStatus::Done === $status) {
                ++$successCount;
            } elseif (NodeStatus::Failed === $status) {
                ++$failureCount;
            }
        }

        $allFailed = 0 === $successCount;
        $replyResult = $context->getResult($plan->replyNode);

        if (null !== $replyResult && $replyResult->isSuccessful()) {
            $content = $replyResult->text ?? '';
            $files = $replyResult->files;
            $metadata = $replyResult->metadata;
        } else {
            // Best-effort recovery: use the last successful textual node + its files.
            [$content, $files, $metadata] = $this->bestEffort($plan, $context);
        }

        $partialFailure = $failureCount > 0 || (null !== $replyResult && !$replyResult->isSuccessful());

        $metadata['multitask'] = [
            'node_statuses' => $statuses,
            'partial_failure' => $partialFailure,
        ];

        return [
            'content' => $content,
            'files' => $files,
            'metadata' => $metadata,
            'node_statuses' => $statuses,
            'partial_failure' => $partialFailure,
            'all_failed' => $allFailed,
        ];
    }

    /**
     * @return array{0: string, 1: list<array<string, mixed>>, 2: array<string, mixed>}
     */
    private function bestEffort(TaskPlan $plan, NodeContext $context): array
    {
        $text = '';
        $files = [];
        $metadata = [];

        foreach ($plan->topologicalOrder() as $node) {
            $result = $context->getResult($node->id);
            if (null === $result || !$result->isSuccessful()) {
                continue;
            }
            if (null !== $result->text && '' !== $result->text) {
                $text = $result->text;
            }
            foreach ($result->files as $file) {
                $files[] = $file;
            }
            $metadata = array_merge($metadata, $result->metadata);
        }

        if ('' === $text) {
            $text = "I couldn't fully complete that request.";
        }

        return [$text, $files, $metadata];
    }
}
