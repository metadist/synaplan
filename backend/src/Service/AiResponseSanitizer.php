<?php

declare(strict_types=1);

namespace App\Service;

/**
 * AI Response Sanitizer.
 *
 * Strips internal AI artifacts that should never be visible to end users —
 * either because they leak system-prompt instructions back into the response
 * (e.g. "[Please reply in German]"), or because they contain reasoning
 * scratchpad output that the rendering layer should not surface (`<think>`).
 *
 * There are two intended call sites:
 *
 *  - `stripForDisplay()` is the strictest pass. Use it for surfaces where the
 *    raw text is shown to humans without the rich client-side processing the
 *    main chat UI does (Discord notifications, plain-text logs, etc.). It
 *    removes `<think>` reasoning blocks AND known leaked instruction notes.
 *
 *  - `stripLeakedInstructions()` only removes the leaked instruction notes
 *    (e.g. "[Please reply in German]") and leaves `<think>` blocks intact.
 *    Use it when storing the AI response for later rendering by the chat UI,
 *    which already collapses `<think>` blocks into a separate "Reasoning"
 *    panel and would otherwise lose that information.
 *
 * Why both? The streaming chat UI deliberately persists reasoning blocks so
 * users with the right model can expand them on demand — wiping them at
 * persist-time would break that UX. But surfaces like Discord just want a
 * clean preview of the actual reply.
 */
final readonly class AiResponseSanitizer
{
    /**
     * Patterns for "leaked" system-prompt instructions that some local /
     * smaller LLMs echo back into their visible response (outside any
     * `<think>` reasoning block). They are always safe to drop.
     *
     * Each pattern is anchored to a square-bracketed annotation that
     * starts with a known instructional verb in EN/DE and references a
     * language. The 1..120 char body limit avoids accidentally eating
     * legitimate user content.
     */
    private const LEAKED_INSTRUCTION_PATTERNS = [
        // English: [Please reply in German], [Please respond in English], ...
        '/\[\s*Please\s+(?:reply|respond|answer|write)\s+in\s+[^\]]{1,80}\]/i',
        // English: [Reply in German], [Respond in English], [Answer in French], ...
        '/\[\s*(?:Reply|Respond|Answer|Write)\s+in\s+[^\]]{1,80}\]/i',
        // German: [Bitte auf Deutsch antworten], [Bitte antworten Sie auf Englisch], ...
        '/\[\s*Bitte[^\]]{1,120}\]/iu',
        // Bare bracketed language directive: [Language: German], [LANG: en], ...
        '/\[\s*(?:Language|Lang)\s*:\s*[^\]]{1,40}\]/i',
    ];

    /**
     * Strict sanitization for surfaces that render plain text without the
     * chat UI's `<think>` handling (Discord embeds, plain-text logs, etc.).
     *
     * Removes:
     *  - `<think>...</think>` reasoning blocks (incl. unterminated trailing ones)
     *  - leaked instruction notes (see `stripLeakedInstructions()`)
     *  - resulting empty leading/trailing whitespace
     */
    public static function stripForDisplay(string $text): string
    {
        if ('' === $text) {
            return '';
        }

        // Closed reasoning blocks first.
        $text = preg_replace('/<think>[\s\S]*?<\/think>/i', '', $text) ?? $text;

        // An unterminated `<think>` (streaming aborted, model misbehaved, …)
        // would otherwise leak the entire reasoning into the preview.
        $text = preg_replace('/<think>[\s\S]*$/i', '', $text) ?? $text;

        $text = self::stripLeakedInstructions($text);

        return trim($text);
    }

    /**
     * Conservative sanitization safe to apply to text we want to persist for
     * later rendering by the chat UI: removes only the leaked instruction
     * notes and keeps `<think>` blocks so they remain available to the
     * collapsible reasoning view.
     */
    public static function stripLeakedInstructions(string $text): string
    {
        if ('' === $text) {
            return '';
        }

        foreach (self::LEAKED_INSTRUCTION_PATTERNS as $pattern) {
            $stripped = preg_replace($pattern, '', $text);
            if (null !== $stripped) {
                $text = $stripped;
            }
        }

        // Tidy up the gap left behind by a stripped annotation without
        // touching the surrounding content layout.
        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return $text;
    }
}
