<?php

declare(strict_types=1);

namespace App\Service\Message;

/**
 * Detects a text-to-speech script that merely repeats the user's own request.
 *
 * The legacy mediamaker/audio path has no content step: the extractor is asked
 * for "the text to be spoken" and, when the message carries none ("teach me the
 * Persian numbers with a children's song"), an LLM tends to hand the instruction
 * straight back. Synthesizing that produces an MP3 of the user's question —
 * the exact failure this guard exists to catch before any provider is called.
 *
 * Deliberately conservative: a delimited payload ("read aloud: …", quotes) is
 * always trusted, short messages are never flagged, and a script only counts as
 * an echo when nearly all of its words come from the request AND it covers most
 * of the request. A legitimately WRITTEN script (a poem, a greeting) introduces
 * new words and passes; a legitimately EXTRACTED one is much shorter than the
 * instruction around it and passes too.
 */
final readonly class TtsScriptGuard
{
    /** Below this many request words a short extraction cannot be told from an echo. */
    private const MIN_REQUEST_WORDS = 4;

    /** Share of script words that must also occur in the request to count as copied. */
    private const MIN_COPIED_WORD_SHARE = 0.9;

    /** Share of the request the script must cover to count as the whole instruction. */
    private const MIN_REQUEST_COVERAGE = 0.7;

    /**
     * Whether speaking `$script` would read the user's own request back to them.
     */
    public static function echoesInstruction(string $script, string $userText): bool
    {
        $normalizedScript = self::normalize($script);
        $normalizedRequest = self::normalize($userText);

        if ('' === $normalizedScript) {
            return true;
        }

        if ('' === $normalizedRequest) {
            return false;
        }

        if ($normalizedScript === $normalizedRequest) {
            return true;
        }

        // "Lies vor: Guten Morgen" / "Say 'hello world'": the user delimited what
        // to speak, so any extraction that differs from the whole message is
        // the payload (or a paraphrase of it) — never the instruction.
        if (self::hasDelimitedPayload($userText)) {
            return false;
        }

        $requestWords = self::words($normalizedRequest);
        $scriptWords = self::words($normalizedScript);

        if (count($requestWords) < self::MIN_REQUEST_WORDS || [] === $scriptWords) {
            return false;
        }

        $requestVocabulary = array_fill_keys($requestWords, true);
        $copied = 0;
        foreach ($scriptWords as $word) {
            if (isset($requestVocabulary[$word])) {
                ++$copied;
            }
        }

        $copiedShare = $copied / count($scriptWords);
        $requestCoverage = count($scriptWords) / count($requestWords);

        return $copiedShare >= self::MIN_COPIED_WORD_SHARE && $requestCoverage >= self::MIN_REQUEST_COVERAGE;
    }

    private static function hasDelimitedPayload(string $text): bool
    {
        // A colon followed by real content, or a quoted run of at least two characters.
        if (1 === preg_match('/:\s*\S{2,}/u', $text)) {
            return true;
        }

        return 1 === preg_match('/["„“”«»‚‘’\'][^"„“”«»‚‘’\']{2,}["„“”«»‚‘’\']/u', $text);
    }

    private static function normalize(string $text): string
    {
        $lower = mb_strtolower($text);
        $stripped = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $lower) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $stripped) ?? '');
    }

    /**
     * @return list<string>
     */
    private static function words(string $normalized): array
    {
        return '' === $normalized ? [] : explode(' ', $normalized);
    }
}
