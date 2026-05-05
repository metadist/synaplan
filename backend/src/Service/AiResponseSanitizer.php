<?php

declare(strict_types=1);

namespace App\Service;

/**
 * AI Response Sanitizer.
 *
 * Strips reasoning scratchpad output (`<think>` blocks) from AI responses
 * before they are shown on surfaces that do not support the rich client-side
 * processing the main chat UI does (Discord previews, plain-text logs, etc.).
 *
 * Why only `<think>` blocks?
 * -------------------------
 * We deliberately do NOT pattern-match instruction leakage like
 * "[Please reply in German]" here. That problem used to be solved by a
 * regex pass at this layer, but regex sanitisation of model output is
 * fragile: every new model variant phrases the leak slightly differently
 * and the regex has to keep growing. Instead, the system prompt is built
 * via {@see Prompt\LanguageDirectiveBuilder}, which appends
 * an explicit anti-echo clause that prevents the leak from being produced
 * in the first place. That is the right architectural layer for the fix.
 *
 * `<think>` stripping stays here because it is genuinely cross-cutting and
 * non-fragile: the tag is part of the protocol some providers stream, the
 * chat UI persists and renders it as a collapsible "Reasoning" panel, and
 * surfaces without that UI must remove it before showing the preview.
 */
final readonly class AiResponseSanitizer
{
    /**
     * Strict sanitization for surfaces that render plain text without the
     * chat UI's `<think>` handling (Discord embeds, plain-text logs, etc.).
     *
     * Removes:
     *  - `<think>...</think>` reasoning blocks
     *  - unterminated trailing `<think>` (e.g. when streaming was cut off)
     *  - resulting leading/trailing whitespace
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

        return trim($text);
    }
}
