<?php

declare(strict_types=1);

namespace App\Service;

/**
 * TTS Text Sanitizer.
 *
 * Strips non-speakable artifacts from AI response text before TTS synthesis.
 * Removes: <think> tags, [Memory:ID] badges, code blocks, markdown formatting, HTML tags.
 *
 * Call TtsTextSanitizer::prepareForSynthesis($text) BEFORE passing text to
 * AiFacade::synthesize() so every caller stays inside provider input limits
 * (OpenAI TTS rejects more than 4096 characters — #1665).
 */
final readonly class TtsTextSanitizer
{
    /** OpenAI TTS input max is 4096; stay under that for every provider. */
    public const MAX_SYNTHESIS_CHARS = 4000;

    /**
     * Strip non-speakable artifacts from AI response text.
     */
    public static function sanitize(string $text): string
    {
        // 1. Remove <think>...</think> reasoning blocks
        $text = preg_replace('/<think>[\s\S]*?<\/think>/i', '', $text);

        // 2. Remove [Memory:ID] badges
        $text = preg_replace('/\[Memory:\d+\]/', '', $text);

        // 3. Remove code blocks (```...```)
        $text = preg_replace('/```[\s\S]*?```/', '', $text);

        // 4. Remove inline code (`...`)
        $text = preg_replace('/`[^`]+`/', '', $text);

        // 5. Remove markdown links [text](url) → keep text
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $text);

        // 6. Remove markdown formatting (**bold**, *italic*, ~~strike~~)
        $text = preg_replace('/\*{1,3}([^*]+)\*{1,3}/', '$1', $text);
        $text = preg_replace('/~~([^~]+)~~/', '$1', $text);

        // 7. Remove headings (## ...)
        $text = preg_replace('/^#{1,6}\s+/m', '', $text);

        // 8. Remove HTML tags
        $text = strip_tags($text);

        // 9. Remove bullet points and list markers
        $text = preg_replace('/^[\s]*[-*•]\s+/m', '', $text);
        $text = preg_replace('/^[\s]*\d+\.\s+/m', '', $text);

        // 10. Remove horizontal rules
        $text = preg_replace('/^[-*_]{3,}$/m', '', $text);

        // 11. Collapse whitespace
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        return $text;
    }

    /**
     * How far back the cut may search for a natural end. A fifth of the window
     * is enough for a sentence in normal prose and still leaves the listener
     * the bulk of the text.
     */
    private const BOUNDARY_SEARCH_RATIO = 0.2;

    /**
     * Cap speakable text at the shared TTS input limit. Multibyte-safe, no
     * ellipsis — the spoken prefix is the content, not a UI snippet.
     *
     * Cuts on the last sentence end inside the window, falling back to the last
     * word break and only then to a hard cut: a voice note that stops mid-word
     * sounds broken, not shortened.
     */
    public static function truncateForSynthesis(string $text, int $maxLength = self::MAX_SYNTHESIS_CHARS): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $window = mb_substr($text, 0, $maxLength);
        $earliestCut = (int) ($maxLength * (1 - self::BOUNDARY_SEARCH_RATIO));

        // Drop the trailing run that carries no sentence end, i.e. cut right
        // after the last '.', '!', '?' or '…' in the window.
        $sentence = preg_replace('/[^.!?…]*\z/u', '', $window);
        if (null !== $sentence && mb_strlen($sentence) >= $earliestCut) {
            return rtrim($sentence);
        }

        // No sentence end close enough: drop the last (probably cut off) word.
        $word = preg_replace('/\s+\S*\z/u', '', $window);
        if (null !== $word && mb_strlen($word) >= $earliestCut) {
            return rtrim($word);
        }

        return $window;
    }

    /**
     * Sanitize then cap — the usual pre-synthesize treatment.
     */
    public static function prepareForSynthesis(string $text): string
    {
        return self::truncateForSynthesis(self::sanitize($text));
    }
}
