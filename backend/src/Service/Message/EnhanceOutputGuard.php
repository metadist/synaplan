<?php

declare(strict_types=1);

namespace App\Service\Message;

/**
 * Detects when an LLM answered with commentary instead of a rewritten message.
 *
 * Design: no keyword lists and no regular expressions — only input/output length
 * ratios and a simple markdown-prefix check. Any refusal phrasing in any language
 * that explodes length vs. a short chat line is caught. The prompt contract
 * (see PromptCatalog tools:enhance) requires __UNENHANCEABLE__ when no rewrite exists.
 */
final class EnhanceOutputGuard
{
    private const UNENHANCEABLE_TOKEN = '__UNENHANCEABLE__';

    /** Above this input length, refusals are usually caught by the global budget alone. */
    private const RATIO_RULE_INPUT_MAX = 40;

    /**
     * Upper bound on plausible output length for a same-intent rewrite (grammar/clarity).
     * Piecewise linear in input length only.
     */
    private static function maxPlausibleEnhancedLength(int $inputLength): int
    {
        if ($inputLength <= 48) {
            return min(4000, (int) round(68 + $inputLength * 5.2));
        }
        if ($inputLength <= 200) {
            return min(8000, (int) round(160 + $inputLength * 2.8));
        }

        return min(16000, (int) round(400 + $inputLength * 2.2));
    }

    public static function isRefusalOrNonEnhancement(string $input, string $output): bool
    {
        $trimmed = trim($output);
        if ('' === $trimmed) {
            return true;
        }

        if (0 === strcasecmp($trimmed, self::UNENHANCEABLE_TOKEN)) {
            return true;
        }

        $inputLength = mb_strlen($input);
        $outputLength = mb_strlen($trimmed);
        if ($inputLength <= 0) {
            return false;
        }

        $cap = self::maxPlausibleEnhancedLength($inputLength);
        if ($outputLength > $cap) {
            return true;
        }

        if (
            $inputLength <= self::RATIO_RULE_INPUT_MAX
            && $outputLength >= 150
            && $outputLength > (int) round($inputLength * 6)
        ) {
            return true;
        }

        if (str_starts_with($trimmed, '**') && $outputLength > 120 && $outputLength > $inputLength + 60) {
            return true;
        }

        return false;
    }
}
