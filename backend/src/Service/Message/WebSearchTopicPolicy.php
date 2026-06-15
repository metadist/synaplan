<?php

declare(strict_types=1);

namespace App\Service\Message;

/**
 * Single source of truth for "is this topic compatible with web search?".
 *
 * Pure asset/document generation topics (image / video / audio / office
 * documents) never benefit from internet context — the downstream handler
 * does not consume search results. Routing them through Brave Search
 * costs quota and adds latency for zero benefit, so they are excluded
 * from the search default regardless of any other signal (including an
 * explicit `tool_internet=true` opt-in: there is nothing useful to do
 * with the results).
 *
 * Used by `MessageProcessor` as the final web-search decision in both the
 * streaming and non-streaming pipelines.
 */
final class WebSearchTopicPolicy
{
    /**
     * Topics whose handler does not consume web context. Asset/document
     * generation only — chat, coding, summarisation and analysis can all
     * benefit from live context and are therefore NOT listed here.
     *
     * @var list<string>
     */
    public const NON_WEB_SEARCH_TOPICS = [
        'mediamaker',
        'officemaker',
        'text2pic',
        'text2vid',
        'text2sound',
        'text2doc',
    ];

    /**
     * True if the topic is a pure asset/document generation topic and
     * web search should be suppressed regardless of the prompt's
     * `tool_internet` flag.
     */
    public static function isNonWebSearchTopic(?string $topic): bool
    {
        return null !== $topic && '' !== $topic && in_array($topic, self::NON_WEB_SEARCH_TOPICS, true);
    }

    /**
     * Decide whether to run a web search, trusting the model's judgment.
     *
     * Decision rule (in order of precedence):
     *   1. Prompt has explicit `tool_internet=true`  → true
     *      (explicit opt-in beats the NON_WEB_SEARCH exclusion: power users
     *      can wire search into a custom media-generation prompt that
     *      consumes web context in its system message, e.g. "image of
     *      today's headlines")
     *   2. Topic is a NON_WEB_SEARCH topic           → false
     *      (the stock handler does not consume web context)
     *   3. Prompt has explicit `tool_internet=false` → false (user opt-out)
     *   4. Otherwise (`tool_internet` is `null`)     → trust the classifier's
     *      `BWEBSEARCH` vote: the AI sorter judges whether the message needs
     *      live information. No vote (e.g. the fast-path heuristic, which
     *      never calls the model) means no search, so trivial chats stay fast.
     *
     * Pass `$promptToolInternet` as the raw value from
     * `$promptMetadata['tool_internet'] ?? null` — the function
     * distinguishes the three states (true / false / null) intentionally.
     * Pass `$classifierVote` as the classifier's `web_search` hint
     * (`$classification['web_search'] ?? null`).
     */
    public static function shouldSearch(?string $topic, ?bool $promptToolInternet, ?bool $classifierVote = null): bool
    {
        // Rule 1: explicit opt-in is absolute.
        if (true === $promptToolInternet) {
            return true;
        }

        // Rule 2: media-generation topics with no explicit opt-in stay off.
        if (self::isNonWebSearchTopic($topic)) {
            return false;
        }

        // Rule 3: explicit opt-out blocks search.
        if (false === $promptToolInternet) {
            return false;
        }

        // Rule 4: no explicit flag → trust the model's BWEBSEARCH vote.
        return true === $classifierVote;
    }
}
