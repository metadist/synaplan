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

    /**
     * An assistant scratchpad is only hidden for the AI. Visitor, operator
     * and system messages may contain the literal tags.
     */
    public static function isAssistantScratchpad(string $direction, string $providerIndex): bool
    {
        if ('OUT' !== $direction) {
            return false;
        }

        return !in_array($providerIndex, ['SYSTEM', 'HUMAN_OPERATOR'], true);
    }

    /**
     * Transcript text for one message. AI scratchpads are removed; every
     * other sender is returned unchanged.
     */
    public static function transcriptText(string $text, string $direction, string $providerIndex): string
    {
        return self::isAssistantScratchpad($direction, $providerIndex)
            ? self::stripForDisplay($text)
            : $text;
    }

    /**
     * Visible text, then the first $limit characters.
     *
     * For an AI message the cut happens after the scratchpad is gone, so a
     * long `<think>` block cannot fill a session preview. Other senders are
     * truncated without removing `<think>`.
     */
    public static function preview(string $text, int $limit = 100, string $direction = 'OUT', string $providerIndex = ''): string
    {
        $visible = self::transcriptText($text, $direction, $providerIndex);
        if ($limit < 1 || '' === $visible) {
            return '';
        }

        return mb_substr($visible, 0, $limit);
    }
}
