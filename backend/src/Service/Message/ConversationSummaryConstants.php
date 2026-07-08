<?php

declare(strict_types=1);

namespace App\Service\Message;

/**
 * Default values for the rolling conversation summary feature.
 *
 * These are the code-level fallbacks used when no BCONFIG override exists
 * (mirrors how FeedbackConfigService falls back to FeedbackConstants). The
 * combined memory window (verbatim recent turns + injected summary) is kept
 * inside the 10 000-15 000 character band the product requires.
 */
final readonly class ConversationSummaryConstants
{
    /** BCONFIG group + owner used for all summary settings. */
    public const CONFIG_GROUP = 'CONVERSATION_SUMMARY';
    public const CONFIG_OWNER_ID = 0;

    /** Master toggle (1 = enabled). */
    public const ENABLED = true;

    /** Lower/upper bounds for the combined conversational memory window. */
    public const MIN_WINDOW_CHARS = 10000;
    public const MAX_WINDOW_CHARS = 15000;

    /** Target combined window (verbatim recent turns + summary), clamped to the band above. */
    public const TARGET_WINDOW_CHARS = 12000;

    /** Character budget reserved for the most recent turns kept verbatim. */
    public const RECENT_VERBATIM_CHARS = 8000;

    /** Hard cap on the injected rolling summary. */
    public const SUMMARY_MAX_CHARS = 4000;

    /** Maximum number of older messages fed to the summarizer (bounds cost). */
    public const MAX_SOURCE_MESSAGES = 200;

    /** Number of recency tiers for gradient compression (older = condensed more). */
    public const TIERS = 3;

    /** Seconds a summary for a stable older span is cached to avoid re-summarizing. */
    public const CACHE_TTL = 3600;

    /** Per-older-message text cap when rendering the summarization source. */
    public const SOURCE_MESSAGE_CHAR_CAP = 4000;
}
