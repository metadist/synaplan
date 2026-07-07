<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Entity\Message;

/**
 * Result of {@see ConversationSummaryService::buildRollingContext()}.
 *
 * When {@see $applied} is false the caller must keep its existing history window
 * untouched (feature disabled, nothing to condense, or the summarizer failed).
 * When true, {@see $summary} holds the condensed rolling summary of the older
 * turns and {@see $recentMessages} holds the newest turns to replay verbatim.
 */
final readonly class RollingSummaryResult
{
    /**
     * @param Message[] $recentMessages newest turns kept verbatim (chronological order)
     */
    public function __construct(
        public bool $applied,
        public ?string $summary,
        public array $recentMessages,
        public int $summarizedCount = 0,
    ) {
    }

    /**
     * Feature is inactive for this turn: keep the caller's original history.
     *
     * @param Message[] $recentMessages
     */
    public static function notApplied(array $recentMessages = []): self
    {
        return new self(false, null, $recentMessages, 0);
    }
}
