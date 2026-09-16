<?php

declare(strict_types=1);

namespace App\Service\Message;

/**
 * How many top search-result pages to fetch and dump into the answering
 * prompt. The sorter's {@see BREADPAGES} vote is the source of truth;
 * this class only clamps it and fills in the "no vote" cases.
 *
 * A research question with no pasted URL needs the article bodies (figures,
 * named companies, sectors) — snippets alone made the model hedge. A
 * weather / ticker / "is X still CEO" question does not: the teasers are
 * enough and fetching three pages would only add latency.
 *
 * Called only after a search has already been decided
 * ({@see WebSearchTopicPolicy::shouldSearch()}); this never starts a search.
 */
final class ReadPagesPolicy
{
    public const NONE = 0;
    public const SHORT = 2;
    public const DEEP = 3;

    /** When the sorter omitted the field but a search is running. */
    public const FALLBACK_WHEN_SEARCH_WITHOUT_VOTE = self::SHORT;

    /**
     * @param int|null $classifierVote         Sorter `BREADPAGES` (0, 2, 3) or null when omitted
     * @param bool     $pastedLinksAlreadyRead The message already had URLs the system fetched
     * @param bool     $searchForced           User toggle / `/search` / prompt `tool_internet=true`
     */
    public static function pagesToRead(?int $classifierVote, bool $pastedLinksAlreadyRead, bool $searchForced): int
    {
        // Pasted links are already in the prompt as "Linked Pages". Dumping
        // two more search-result pages on top is noise unless the sorter
        // explicitly asked for them.
        if ($pastedLinksAlreadyRead && (null === $classifierVote || $classifierVote <= 0)) {
            return self::NONE;
        }

        if (null !== $classifierVote) {
            return self::clamp($classifierVote);
        }

        // Fast-path / old prompt / rule-based topic: no BREADPAGES vote.
        // This method is only called when a search is already running, so
        // default to two page dumps — a research question must not regress
        // to snippet-only hedging. Forced search (`/search`, tool_internet)
        // and a search vote that omitted the field share the same default.
        return $searchForced ? self::SHORT : self::FALLBACK_WHEN_SEARCH_WITHOUT_VOTE;
    }

    /**
     * Allowed values are 0, 2, 3. 1 rounds up (a single page is rarely
     * enough diversity); anything above 3 is capped.
     */
    public static function clamp(int $pages): int
    {
        if ($pages <= 0) {
            return self::NONE;
        }
        if (1 === $pages) {
            return self::SHORT;
        }

        return min(self::DEEP, $pages);
    }
}
