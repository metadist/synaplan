<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\Ledger\PassageText;

/**
 * Splits a draft into the statements the fact-check looks at. Headings,
 * the sources section, table rules and table headers are skipped. A list
 * marker such as "1." or "-" is not part of a statement, the dot in
 * "12. März", "Mar. 12", "Art. 5" or "z. B." does not end one, and
 * markers that stand after the full stop belong to the sentence before
 * them.
 */
final class DraftSentences
{
    public const MARKER = '/\[\s*e\d+(?:\s*,\s*e\d+)*\s*\]/i';

    private const SOURCES = '/^#{1,6}\s*(?:sources?|references?|quellen|literatur|fuentes|referencias|r[ée]f[ée]rences|kaynaklar|kaynak[çc]a)\b/iu';
    private const ABBREVIATION_END = '/(?:\b(?:Art|Abs|Buchst|Bst|Nr|Ziff|lit|Kap|Rn|Anh|bzw|ca|vgl|ggf|Mio|Mrd|etc|vs|No|Vol|Sec|Ch|pp|ff|Dr|Prof|St|Mr|Mrs|Ms|Mme|cf|al)|\b\p{L}|(?<![\d.])\d{1,2})\.$/u';
    private const LIST_MARKER = '/^(?:[-*+•]|\d{1,3}[.)])\s+/u';
    private const TABLE_RULE = '/^\|?\s*:?-{3,}:?\s*(?:\|\s*:?-{3,}:?\s*)*\|?$/';

    /**
     * @return list<string>
     */
    public static function split(string $report): array
    {
        $lines = preg_split("/\r\n|\r|\n/u", $report) ?: [];
        $out = [];
        $inSources = false;
        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '#')) {
                $inSources = 1 === preg_match(self::SOURCES, $trimmed);
                continue;
            }
            if ($inSources || '' === $trimmed || self::isTableRule($trimmed) || self::isTableRule(trim($lines[$index + 1] ?? ''))) {
                continue;
            }
            $trimmed = preg_replace(self::LIST_MARKER, '', $trimmed) ?? $trimmed;
            foreach (self::sentencesOf($trimmed) as $part) {
                $markersOnly = '' === trim((string) preg_replace(self::MARKER, '', $part));
                if ($markersOnly && [] !== $out) {
                    $out[array_key_last($out)] .= ' '.$part;
                    continue;
                }
                $out[] = $part;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function sentencesOf(string $line): array
    {
        $parts = preg_split('/(?<=[.!?])\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($parts as $part) {
            if ([] !== $out && self::dotInsideSentence($out[array_key_last($out)], $part)) {
                $out[array_key_last($out)] .= ' '.$part;
                continue;
            }
            $out[] = $part;
        }

        return $out;
    }

    private static function dotInsideSentence(string $before, string $after): bool
    {
        if (1 === preg_match(self::ABBREVIATION_END, $before)) {
            return true;
        }

        return 1 === preg_match('/(?:^|\P{L})(\p{L}+)\.$/u', $before, $tail)
            && PassageText::isMonth($tail[1])
            && 1 === preg_match('/^\d/', $after);
    }

    private static function isTableRule(string $line): bool
    {
        return str_contains($line, '-') && 1 === preg_match(self::TABLE_RULE, $line);
    }
}
