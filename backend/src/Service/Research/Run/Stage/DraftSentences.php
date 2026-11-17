<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\Ledger\PassageText;

/**
 * Splits a draft into the statements the fact-check looks at. Headings,
 * the sources section, table rules and table headers are skipped. A table
 * row is one statement, whatever full stops its cells hold: "feat." or two
 * sentences in a cell do not break the row apart. Inside a table a row may
 * leave out its outer pipes. A list marker such as "1." or "-" is not part
 * of a statement, the dot in "12. März", "Mar. 12", "Art. 5", "z. B." or
 * "feat. Rihanna" does not end one, nor does a dot inside a bracket that
 * is still open, as in "(approx. 5 weeks)". A part that starts with a
 * comma or a small letter (after "..." inside a quote) continues the
 * sentence. Markers that stand after the full stop belong to the sentence
 * before them and close it: only punctuation, or the emphasis that wraps
 * the sentence, still joins it after them.
 *
 * A statement from one line is that line's text as written, white space
 * included, so the check finds it in the report again when it removes it.
 */
final class DraftSentences
{
    public const MARKER = '/\[\s*e\d+(?:\s*,\s*e\d+)*\s*\]/i';

    private const SOURCES = '/^#{1,6}\s*(?:sources?|references?|quellen|literatur|fuentes|referencias|r[ée]f[ée]rences|kaynaklar|kaynak[çc]a)\b/iu';
    private const ABBREVIATION_END = '/(?:\b(?:Art|Abs|Buchst|Bst|Nr|Ziff|lit|Kap|Rn|Anh|bzw|ca|vgl|ggf|Mio|Mrd|etc|vs|No|Vol|Sec|Ch|pp|ff|Dr|Prof|St|Mr|Mrs|Ms|Mme|cf|al|feat|Feat)|\b\p{L}|(?<![\d.])\d{1,2})\.$/u';
    private const CONTINUES = '/^(?:[,;:)\]]|\p{Ll})/u';
    private const CONTINUES_AFTER_MARKERS = '/^[,;:)\]]/u';
    private const LEADING_MARKERS = '/^((?:\[\s*e\d+(?:\s*,\s*e\d+)*\s*\]\s*)+)(.*)$/isu';
    private const WORD = '/[\p{L}\p{N}]/u';
    /** How far back an open bracket is looked for, so a bracket nobody closed does not swallow the paragraph. */
    private const BRACKET_REACH = 240;
    private const LIST_MARKER = '/^(?:[-*+•]|\d{1,3}[.)])\s+/u';
    public const TABLE_RULE = '/^\|?\s*:?-{3,}:?\s*(?:\|\s*:?-{3,}:?\s*)*\|?$/';

    /**
     * @return list<string>
     */
    public static function split(string $report): array
    {
        $lines = preg_split("/\r\n|\r|\n/u", $report) ?: [];
        $out = [];
        $inSources = false;
        $inTable = false;
        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '#')) {
                $inSources = 1 === preg_match(self::SOURCES, $trimmed);
                $inTable = false;
                continue;
            }
            if ('' === $trimmed) {
                $inTable = false;
                continue;
            }
            if (self::isTableRule($trimmed)) {
                $inTable = str_contains($trimmed, '|');
                continue;
            }
            if ($inSources || self::isTableRule(trim($lines[$index + 1] ?? ''))) {
                continue;
            }
            if (self::isTableRow($trimmed) || ($inTable && str_contains($trimmed, '|'))) {
                $out[] = $trimmed;
                continue;
            }
            $inTable = false;
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
        // Every second entry is the white space that stood between two parts.
        $parts = preg_split('/(?<=[.!?])(\s+)/u', $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $out = [];
        $gap = '';
        for ($i = 0, $count = count($parts); $i < $count; $i += 2) {
            $part = $parts[$i];
            $closed = false;
            // "One. [e1] Two. [e2]" breaks after each full stop, so [e1] opens the second part.
            if ([] !== $out && 1 === preg_match(self::LEADING_MARKERS, $part, $match)) {
                $markers = rtrim($match[1]);
                $out[array_key_last($out)] .= $gap.$markers;
                $gap = substr($match[1], strlen($markers));
                $part = $match[2];
                $closed = true;
            }
            if ('' !== $part) {
                if ([] !== $out && self::continues($out[array_key_last($out)], $part, $closed)) {
                    $out[array_key_last($out)] .= $gap.$part;
                } else {
                    $out[] = $part;
                }
            }
            $gap = $parts[$i + 1] ?? '';
        }

        return $out;
    }

    /** The sentence without citation markers and the space they left before punctuation. */
    public static function bare(string $sentence): string
    {
        $text = (string) preg_replace(self::MARKER, '', $sentence);
        $text = (string) preg_replace('/\s+([,.;:!?])/u', '$1', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** A line that opens and closes with a pipe: a table header or one of its rows. */
    public static function isTableRow(string $line): bool
    {
        $line = trim($line);

        return str_starts_with($line, '|') && str_ends_with($line, '|') && strlen($line) > 1;
    }

    /**
     * Whether a part belongs to the statement before it. After markers
     * that statement is closed, so a small letter starts a new one
     * ("iPhone costs …"). A part without a letter or a digit is what closes
     * the statement: the emphasis around it, a quote mark, a bracket.
     */
    private static function continues(string $before, string $part, bool $afterMarkers): bool
    {
        if (1 !== preg_match(self::WORD, $part)) {
            return true;
        }

        return $afterMarkers
            ? 1 === preg_match(self::CONTINUES_AFTER_MARKERS, $part)
            : self::dotInsideSentence($before, $part);
    }

    private static function dotInsideSentence(string $before, string $after): bool
    {
        if (1 === preg_match(self::CONTINUES, $after)
            || 1 === preg_match(self::ABBREVIATION_END, $before)
            || self::insideBracket($before)) {
            return true;
        }

        return 1 === preg_match('/(?:^|\P{L})(\p{L}+)\.$/u', $before, $tail)
            && PassageText::isMonth($tail[1])
            && 1 === preg_match('/^\d/', $after);
    }

    /** Whether the text ends inside a round bracket, counting brackets inside brackets. */
    private static function insideBracket(string $text): bool
    {
        $depth = 0;
        foreach (mb_str_split(mb_substr($text, -self::BRACKET_REACH)) as $char) {
            if ('(' === $char) {
                ++$depth;
            } elseif (')' === $char && $depth > 0) {
                --$depth;
            }
        }

        return $depth > 0;
    }

    private static function isTableRule(string $line): bool
    {
        return str_contains($line, '-') && 1 === preg_match(self::TABLE_RULE, $line);
    }
}
