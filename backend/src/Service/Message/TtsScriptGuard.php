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
 * always trusted, short messages are never flagged, and a script that differs
 * from the request only counts as an echo when it (a) still OPENS with a
 * request verb ("teach me…", "bring mir… bei", "erkläre…"), (b) is built
 * almost entirely from the request's words and (c) covers most of the
 * request. A legitimately WRITTEN script (a poem, a greeting) introduces new
 * words and passes; a legitimately EXTRACTED one ("say hello world now" →
 * "hello world now") no longer starts with the instruction verb and passes
 * too, however short the surrounding instruction was.
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
     * Imperatives that ask for content to be produced. A TTS script that still
     * begins with one of these is the instruction, not the text to speak.
     * "say"/"read"/"speak" are deliberately absent: those introduce a payload,
     * and stripping them is exactly what a correct extraction does.
     *
     * @var list<string>
     */
    private const REQUEST_VERBS = [
        // en
        'teach', 'explain', 'write', 'create', 'make', 'generate', 'compose', 'tell', 'give',
        'show', 'help', 'describe', 'translate', 'summarize', 'summarise', 'invent', 'draft', 'list',
        // de
        'bring', 'bringe', 'lehre', 'lehr', 'erkläre', 'erklär', 'schreib', 'schreibe', 'erstelle',
        'erstell', 'mach', 'mache', 'generiere', 'generier', 'erzähl', 'erzähle', 'gib', 'zeig',
        'zeige', 'hilf', 'beschreib', 'beschreibe', 'übersetze', 'übersetz', 'fasse', 'erfinde', 'entwirf',
        // fr / es / tr
        'apprends', 'explique', 'écris', 'crée', 'fais', 'raconte', 'enséñame', 'explica', 'escribe',
        'crea', 'haz', 'cuéntame', 'öğret', 'açıkla', 'yaz', 'oluştur', 'anlat',
    ];

    /**
     * Politeness openers skipped before looking for the request verb.
     *
     * @var list<string>
     */
    private const POLITE_OPENERS = ['please', 'bitte', 'kindly', 'lütfen'];

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

        // "hello world now" for "Say hello world now" is a payload with the
        // instruction verb removed — the shape a correct extraction has. Only
        // a script that still opens with a content-producing imperative can be
        // the instruction itself.
        if (!self::opensWithRequestVerb($scriptWords)) {
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

    /**
     * @param list<string> $scriptWords normalized words of the script
     */
    private static function opensWithRequestVerb(array $scriptWords): bool
    {
        foreach ($scriptWords as $word) {
            if (in_array($word, self::POLITE_OPENERS, true)) {
                continue;
            }

            return in_array($word, self::REQUEST_VERBS, true);
        }

        return false;
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
