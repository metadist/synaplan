<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Shared constants for the feedback system (false positives, positive examples, contradictions).
 *
 * All Qdrant namespace names and score thresholds are defined here to avoid
 * duplication and ensure consistency across FeedbackExampleService,
 * FeedbackContradictionService, and ChatHandler.
 */
final class FeedbackConstants
{
    // --- Qdrant namespace identifiers ---
    public const NAMESPACE_FALSE_POSITIVE = 'feedback_false_positive';
    public const NAMESPACE_POSITIVE = 'feedback_positive';

    // --- Score thresholds ---

    /** Minimum score for feedback results used as chat context (must be clearly on-topic). */
    public const MIN_CHAT_FEEDBACK_SCORE = 0.55;

    /** Minimum score for memories loaded as chat context. */
    public const MIN_CHAT_MEMORY_SCORE = 0.4;

    /** Minimum score for contradiction detection (slightly lower to catch edge cases). */
    public const MIN_CONTRADICTION_SCORE = 0.4;

    /** Minimum score for KB research on documents. */
    public const MIN_RESEARCH_SCORE = 0.35;

    /** Minimum score for KB research on memories (higher because memory results are short). */
    public const MIN_MEMORY_RESEARCH_SCORE = 0.55;

    /** Minimum score for memory extraction context (intentionally low to find related memories). */
    public const MIN_EXTRACTION_SCORE = 0.25;

    // --- Limits ---
    public const LIMIT_PER_NAMESPACE = 5;
}
