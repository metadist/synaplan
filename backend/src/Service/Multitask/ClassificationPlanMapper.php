<?php

declare(strict_types=1);

namespace App\Service\Multitask;

use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Plan\TaskPlan;

/**
 * Bridges the legacy `$classification` array and the task-plan world (the
 * §3.3 "single task is just a degenerate plan" adapter).
 *
 * Sprint 2 uses this to wrap the EXISTING classification (from the legacy
 * classifier / widget / again / fixed-prompt branches) into a one-node plan so
 * the executor seam can run without changing behaviour. To make the round-trip
 * provably lossless, the full classification array is carried verbatim in the
 * node params under {@see CLASSIFICATION_KEY}; {@see classificationFromNode()}
 * returns it unchanged. The node's capability is derived from the intent purely
 * for readable BMESSAGE_TASKS rows — execution always uses the carried array.
 */
final class ClassificationPlanMapper
{
    /** Reserved param key holding the verbatim legacy classification. */
    public const CLASSIFICATION_KEY = '_classification';

    /**
     * Wrap a classification array into a degenerate single-node plan.
     *
     * @param array<string, mixed> $classification
     */
    public function toSingleNodePlan(array $classification): TaskPlan
    {
        $language = is_string($classification['language'] ?? null) ? $classification['language'] : 'en';
        $capability = $this->capabilityForClassification($classification);

        return TaskPlan::fromArray([
            'version' => 1,
            'language' => $language,
            'reply_node' => 'n1',
            'tasks' => [[
                'id' => 'n1',
                'capability' => $capability->value,
                'params' => [self::CLASSIFICATION_KEY => $classification],
            ]],
        ]);
    }

    /**
     * Recover the legacy classification array a runner should feed the handler.
     *
     * Lossless inverse of {@see toSingleNodePlan()} for nodes that carry the
     * reserved key (Sprint 2 single-node plans).
     *
     * @return array<string, mixed>|null
     */
    public function classificationFromNode(TaskNode $node): ?array
    {
        $carried = $node->params[self::CLASSIFICATION_KEY] ?? null;

        return is_array($carried) ? $carried : null;
    }

    /**
     * Map a classification's intent (and media_type) to a plan capability.
     *
     * Used for the persisted node label AND — since #1072 — as the safety guard
     * that lets {@see TaskPlanExecutor} collapse a redundant single-media plan
     * back to the legacy single-node path only when the legacy classification
     * would produce the SAME media. Never used to pick a model.
     *
     * @param array<string, mixed> $classification
     */
    public function capabilityForClassification(array $classification): Capability
    {
        $intent = is_string($classification['intent'] ?? null) ? $classification['intent'] : 'chat';

        if ('image_generation' === $intent) {
            return match ($classification['media_type'] ?? null) {
                'video' => Capability::VideoGeneration,
                'audio' => Capability::Text2Sound,
                default => Capability::ImageGeneration,
            };
        }

        return match ($intent) {
            'file_analysis' => Capability::FileAnalysis,
            'document_generation' => Capability::DocumentGeneration,
            default => Capability::Chat,
        };
    }
}
