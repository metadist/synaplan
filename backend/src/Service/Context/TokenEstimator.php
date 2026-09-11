<?php

declare(strict_types=1);

namespace App\Service\Context;

/**
 * Deterministic, provider-neutral token estimate.
 *
 * We never have the target tokenizer in PHP, so the estimate is deliberately
 * conservative: a text is assumed to tokenize DENSER than typical English
 * prose so a budget computed from it stays inside the real window. Markdown
 * tables (the shape spreadsheets are extracted into) and CJK-heavy text are
 * denser still and get a lower chars-per-token ratio.
 *
 * Pure function, no I/O — cheap enough to call on every request.
 */
final class TokenEstimator
{
    /** Prose: ~4 chars/token for English, ~3.5 for German/French; use 3.5. */
    public const CHARS_PER_TOKEN_PROSE = 3.5;

    /** Markdown tables / pipes / numbers tokenize at roughly 2.6 chars per token. */
    public const CHARS_PER_TOKEN_TABLE = 2.6;

    /** CJK scripts: roughly one token per 1.5 characters. */
    public const CHARS_PER_TOKEN_CJK = 1.5;

    /** Share of pipe/digit characters above which text is treated as tabular. */
    private const TABLE_DENSITY_THRESHOLD = 0.12;

    /** Share of CJK characters above which text is treated as CJK. */
    private const CJK_DENSITY_THRESHOLD = 0.2;

    public function estimate(string $text): int
    {
        $length = mb_strlen($text);
        if (0 === $length) {
            return 0;
        }

        return (int) ceil($length / $this->charsPerToken($text));
    }

    /**
     * Inverse of {@see estimate()} for a budget: how many characters of THIS
     * kind of text fit into `$tokens`.
     */
    public function charsForTokens(int $tokens, string $sample = ''): int
    {
        if ($tokens <= 0) {
            return 0;
        }

        return (int) floor($tokens * $this->charsPerToken($sample));
    }

    /**
     * Chars-per-token ratio for the given text shape. Public so callers that
     * split text into chunks can size them consistently.
     */
    public function charsPerToken(string $text): float
    {
        if ('' === $text) {
            return self::CHARS_PER_TOKEN_PROSE;
        }

        // Sample at most the first 20k chars — density is stable across a document.
        $sample = mb_substr($text, 0, 20000);
        $sampleLength = max(1, mb_strlen($sample));

        $cjk = preg_match_all('/[\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}]/u', $sample);
        if (false !== $cjk && $cjk / $sampleLength >= self::CJK_DENSITY_THRESHOLD) {
            return self::CHARS_PER_TOKEN_CJK;
        }

        $tabular = preg_match_all('/[|0-9]/', $sample);
        if (false !== $tabular && $tabular / $sampleLength >= self::TABLE_DENSITY_THRESHOLD) {
            return self::CHARS_PER_TOKEN_TABLE;
        }

        return self::CHARS_PER_TOKEN_PROSE;
    }
}
