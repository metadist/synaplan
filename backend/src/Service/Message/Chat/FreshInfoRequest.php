<?php

declare(strict_types=1);

namespace App\Service\Message\Chat;

/**
 * The answering model's own request for a web search.
 *
 * The sorter decides before the answer whether a message needs the web. A
 * question it rated as general knowledge can still turn out to need facts
 * newer than the model's training ("who is the current …", "the latest
 * version of …"). Instead of hedging or guessing, the model answers with
 * only this marker and a search phrase; the turn then searches and the
 * model answers again with the results. The person never sees the marker:
 * the stream holds its first characters back until they are known to be an
 * answer, and the buffered channels read the whole reply before sending.
 *
 * The request travels in the handler result under {@see self::RESULT_KEY},
 * like a routing directive: an expected outcome of the call, not an error.
 */
final readonly class FreshInfoRequest
{
    /** The option the processor sets when the turn can still search after the model asked. */
    public const OPTION = 'web_search_on_request';

    /** The handler result key carrying the request to the processor. */
    public const RESULT_KEY = 'web_search_request';

    public const MARKER_OPEN = '[[SEARCH_WEB:';

    public const MARKER_CLOSE = ']]';

    private const MAX_QUERY_CHARS = 200;

    /**
     * The instruction appended to the system prompt when a request can be
     * honoured. Deliberately narrow: the sorter already searched for the
     * questions it recognised, this catches the ones it did not.
     */
    public static function instruction(): string
    {
        return "\n\n## When you lack current information\n\n"
            .'If a correct answer depends on facts that may have changed since your training — '
            .'the current holder of an office or job, the latest version or release, a price, '
            .'a date, a schedule, the outcome of a recent event — and this conversation does not '
            ."contain them, do NOT guess, hedge, or explain what you cannot know.\n"
            .'Instead reply with exactly one line and nothing else: '.self::MARKER_OPEN.' <search phrase>'.self::MARKER_CLOSE."\n"
            .'The search phrase is what you would type into a search engine, in the language of the question. '
            .'The system then searches the web and asks you again with the results. '
            .'Answer normally when the conversation, the attached material or stable general knowledge is enough.';
    }

    /**
     * The search phrase when the whole reply is the marker, otherwise null.
     * A marker buried in a normal answer is not a request.
     */
    public static function parse(?string $text): ?string
    {
        if (null === $text) {
            return null;
        }
        $trimmed = trim(preg_replace('/<think>.*?<\/think>/s', '', $text) ?? $text);
        if (!str_starts_with($trimmed, self::MARKER_OPEN)) {
            return null;
        }
        $close = strpos($trimmed, self::MARKER_CLOSE, strlen(self::MARKER_OPEN));
        if (false === $close) {
            return null;
        }
        if ('' !== trim(substr($trimmed, $close + strlen(self::MARKER_CLOSE)))) {
            return null;
        }
        $query = trim(substr($trimmed, strlen(self::MARKER_OPEN), $close - strlen(self::MARKER_OPEN)));
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? $query, " \t\"'");
        if ('' === $query) {
            return null;
        }

        return mb_substr($query, 0, self::MAX_QUERY_CHARS);
    }

    /**
     * Whether the text seen so far could still become the marker: it is a
     * prefix of the marker, or starts with it. Decides how long a stream
     * holds its first characters back.
     */
    public static function couldBecomeMarker(string $head): bool
    {
        $probe = ltrim($head);
        if ('' === $probe) {
            return true;
        }

        return str_starts_with($probe, self::MARKER_OPEN) || str_starts_with(self::MARKER_OPEN, $probe);
    }

    /**
     * The handler result that asks the processor to search and dispatch again.
     * The metadata is the probing call's (provider, model, usage), so the
     * turn's usage can still count it.
     *
     * @param array<string, mixed> $metadata
     *
     * @return array{content: string, metadata: array<string, mixed>, web_search_request: string}
     */
    public static function handlerResult(string $query, array $metadata): array
    {
        return ['content' => '', 'metadata' => $metadata, self::RESULT_KEY => $query];
    }

    /**
     * @param array<string, mixed> $handlerResult
     */
    public static function fromHandlerResult(array $handlerResult): ?string
    {
        $query = $handlerResult[self::RESULT_KEY] ?? null;

        return is_string($query) && '' !== $query ? $query : null;
    }
}
