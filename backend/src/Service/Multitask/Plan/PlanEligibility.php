<?php

declare(strict_types=1);

namespace App\Service\Multitask\Plan;

/**
 * Which classified turns may be planned, and when a one-node plan may be
 * handed back to the legacy router.
 *
 * A chat pinned to an assistant (`source = agent`) skips the sorter and stays
 * `intent = chat`. It still has to be planned: otherwise "save this into a
 * text file" is only talked about. The legacy router executes the
 * classification, not the planned node, so that file step stays on the DAG.
 */
final class PlanEligibility
{
    /**
     * @var list<string>
     */
    public const SOURCES = [
        'ai_sorting',
        'attachment_document_or_audio',
        'saved_task',
        'agent',
    ];

    public static function shouldPlan(?string $source): bool
    {
        return in_array($source, self::SOURCES, true);
    }

    /**
     * A one-node plan uses the legacy router when that router would perform
     * the same step, or when planning gave up. A chat classification with a
     * different planned step (a pinned assistant asked to save a file) stays
     * on the DAG.
     */
    public static function useLegacyRouter(bool $fallback, Capability $planned, Capability $classified): bool
    {
        if ($planned === $classified || $fallback) {
            return true;
        }

        return Capability::Chat !== $classified;
    }
}
