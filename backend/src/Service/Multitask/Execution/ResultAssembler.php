<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution;

use App\Service\Multitask\Plan\Capability;
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
     *     node_job_keys: array<string, string>,
     *     partial_failure: bool,
     *     all_failed: bool
     * }
     */
    public function assemble(TaskPlan $plan, NodeContext $context): array
    {
        $statuses = [];
        $jobKeys = [];
        $successCount = 0;
        $failureCount = 0;
        foreach ($plan->nodes as $node) {
            $result = $context->getResult($node->id);
            $status = null !== $result ? $result->status : NodeStatus::Pending;
            $statuses[$node->id] = $status->value;
            if (null !== $result) {
                $mediaJob = $result->metadata['media_job'] ?? null;
                if (is_array($mediaJob) && is_string($mediaJob['job_id'] ?? null) && '' !== $mediaJob['job_id']) {
                    $jobKeys[$node->id] = $mediaJob['job_id'];
                }
            }
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

        // Issue #1197: the reply node is frequently `compose_reply`, whose
        // runner carries NO provider/model metadata, so the inference provider
        // that actually produced the answer (e.g. groq) is dropped and the chat
        // bubble falls back to a wrong/default avatar. Backfill the chat-model
        // meta from the last successful node that recorded a provider (the node
        // closest to the final answer in execution order), so StreamController
        // persists the real ai_chat_provider/model.
        if (empty($metadata['provider'])) {
            foreach ($plan->topologicalOrder() as $node) {
                $nodeResult = $context->getResult($node->id);
                if (null === $nodeResult || !$nodeResult->isSuccessful()) {
                    continue;
                }
                $nodeProvider = $nodeResult->metadata['provider'] ?? null;
                if (!is_string($nodeProvider) || '' === $nodeProvider) {
                    continue;
                }
                // Last provider-bearing node wins (do not break): it is the one
                // that produced the user-facing answer text.
                $metadata['provider'] = $nodeProvider;
                if (isset($nodeResult->metadata['model'])) {
                    $metadata['model'] = $nodeResult->metadata['model'];
                }
                if (isset($nodeResult->metadata['model_id'])) {
                    $metadata['model_id'] = $nodeResult->metadata['model_id'];
                }
            }
        }

        // Propagate web_search results from the first successful WebSearch node
        // into the top-level metadata so StreamController can set the
        // web_search_query/count metas and populate the Sources dropdown.
        // The replyNode is typically chat/summarize/compose_reply and does NOT
        // carry search_results in its own metadata, which is why DAG turns were
        // missing the Sources dropdown (issue: QA feedback PR #1076).
        if (!isset($metadata['search_results'])) {
            foreach ($plan->nodes as $node) {
                if (Capability::WebSearch !== $node->capability) {
                    continue;
                }
                $searchNodeResult = $context->getResult($node->id);
                if (null === $searchNodeResult || !$searchNodeResult->isSuccessful()) {
                    continue;
                }
                $sr = $searchNodeResult->metadata['search_results'] ?? null;
                if (is_array($sr) && !empty($sr['results'])) {
                    $metadata['search_results'] = $sr;
                    break;
                }
            }
        }

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
            $nodeStatus = null !== $nodeResult ? $nodeResult->status->value : 'pending';
            $card = [
                'nodeId' => $node->id,
                'capability' => $node->capability->value,
                'kind' => $node->capability->uiKind(),
                'state' => $nodeStatus,
            ];
            if (null !== $nodeResult) {
                if ('search' === $node->capability->uiKind()) {
                    // Store a compact summary (query + count) instead of the full
                    // formatted text dump. The full results are available via the
                    // Sources dropdown on the message body — showing a 10-item dump
                    // in the task card is verbose and redundant (QA feedback #1076).
                    $srMeta = $nodeResult->metadata['search_results'] ?? null;
                    $srQuery = is_string($nodeResult->metadata['query'] ?? null) ? $nodeResult->metadata['query'] : '';
                    $srCount = is_array($srMeta) && is_array($srMeta['results'] ?? null)
                        ? count($srMeta['results'])
                        : 0;
                    if ('' !== $srQuery) {
                        $card['query'] = $srQuery;
                    }
                    if ($srCount > 0) {
                        $card['resultsCount'] = $srCount;
                    }
                } elseif (null !== $nodeResult->text && '' !== $nodeResult->text) {
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
                $mediaJob = $nodeResult->metadata['media_job'] ?? null;
                if (is_array($mediaJob) && is_string($mediaJob['job_id'] ?? null) && '' !== $mediaJob['job_id']) {
                    $card['job_id'] = $mediaJob['job_id'];
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
            'node_job_keys' => $jobKeys,
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
