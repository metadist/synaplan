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
     * Static fallback shown when no node produced any text. Keyed by the
     * detected message language (the frontend-supported set); English is the
     * fallback for everything else. The backend has no translator service —
     * normal replies are localized by the model itself, so only this
     * degraded-path constant needs a map.
     */
    private const FALLBACK_TEXT = [
        'en' => "I couldn't fully complete that request.",
        'de' => 'Ich konnte diese Anfrage leider nicht vollständig abschließen.',
        'es' => 'No pude completar esa solicitud por completo.',
        'tr' => 'Bu isteği tamamen tamamlayamadım.',
    ];

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

        // Persist the per-node render state so the frontend can rebuild the
        // task cards on reload without another round-trip. Only non-hidden
        // nodes (uiKind !== 'hidden') produce visible cards — compose_reply
        // is the assembler and never has its own card.
        $renderCards = [];
        foreach ($plan->nodes as $node) {
            if ('hidden' === $node->capability->uiKind()) {
                continue;
            }
            $nodeResult = $context->getResult($node->id);
            $nodeStatus = null !== $nodeResult ? $nodeResult->status->value : 'skipped';
            $card = [
                'nodeId' => $node->id,
                'capability' => $node->capability->value,
                'kind' => $node->capability->uiKind(),
                'state' => $nodeStatus,
            ];
            if (null !== $nodeResult) {
                if (null !== $nodeResult->text && '' !== $nodeResult->text) {
                    $card['text'] = $nodeResult->text;
                }
                // First file from this node provides the media url/type for the card.
                $firstFile = $nodeResult->firstFile();
                if (null !== $firstFile && isset($firstFile['path'])) {
                    $card['url'] = (string) $firstFile['path'];
                    $card['type'] = isset($firstFile['type']) ? (string) $firstFile['type'] : $node->capability->uiKind();
                }
                if (null !== $nodeResult->error && '' !== $nodeResult->error) {
                    $card['error'] = $nodeResult->error;
                }
            }
            $renderCards[] = $card;
        }

        $metadata['task_plan_render'] = [
            'reply_node' => $plan->replyNode,
            'cards' => $renderCards,
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
            $language = is_string($context->classification['language'] ?? null)
                ? $context->classification['language']
                : ($context->message->getLanguage() ?: 'en');
            $text = self::FALLBACK_TEXT[$language] ?? self::FALLBACK_TEXT['en'];
        }

        return [$text, $files, $metadata];
    }
}
