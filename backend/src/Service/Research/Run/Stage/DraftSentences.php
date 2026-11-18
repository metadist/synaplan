<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\Ledger\PassageText;

/**
 * Splits a draft into the statements the fact-check looks at. Headings,
 * the sources section, table rules and table headers are skipped. A table
 * row is one statement, whatever full stops its cells hold: "feat." or two
 * sentences in a cell do not break the row apart. Which lines are rows is
 * decided in one place, tableRows(). A list marker such as "1." or "-" is not part
 * of a statement, the dot in "12. März", "Mar. 12", "Art. 5", "z. B." or
 * "feat. Rihanna" does not end one, nor does a dot inside a bracket that
 * is still open, as in "(approx. 5 weeks)". A part that starts with a
 * comma or a small letter (after "..." inside a quote) continues the
 * sentence. Markers that stand after the full stop belong to the sentence
 * before them and close it: only punctuation, the emphasis that wraps the
 * sentence, or a bracket that is still open joins what follows to it.
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
    private const CLOSING_MARKS = '/^([*_~`"\'”’»›]+)(\s*)(.*)$/su';
    private const BLOCK_START = '/^(?:#{1,6}\s|[-*+•]\s|\d{1,3}[.)]\s|>|`{3}|~{3})/u';
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
        $rows = self::tableRows($lines);
        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '#')) {
                $inSources = 1 === preg_match(self::SOURCES, $trimmed);
                continue;
            }
            if ($inSources || '' === $trimmed || self::isTableRule($trimmed) || self::isTableRule(trim($lines[$index + 1] ?? ''))) {
                continue;
            }
            if (isset($rows[$index])) {
                $out[] = $trimmed;
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
                // "**One. [e1]** Two." closes its emphasis right after the marker.
                if ('' === $gap && 1 === preg_match(self::CLOSING_MARKS, $part, $close)) {
                    $out[array_key_last($out)] .= $close[1];
                    $gap = $close[2];
                    $part = $close[3];
                }
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

    /**
     * The lines that are rows of a table, by index; a header and its rule
     * line are not. A line that opens and closes with a pipe is always a
     * row. Under a header and a rule line a row may leave the outer pipes
     * out ("Song | Notes" over "--- | ---"); such a table ends at a blank
     * line, at a line without a pipe, or where another block starts: a
     * heading, a list item, a quote, a code fence.
     *
     * @param list<string> $lines
     *
     * @return array<int, true>
     */
    public static function tableRows(array $lines): array
    {
        $rows = [];
        $inTable = false;
        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            if (self::isTableRule($trimmed)) {
                $inTable = str_contains($trimmed, '|') && str_contains($lines[$index - 1] ?? '', '|');
                continue;
            }
            if (self::isTableRule(trim($lines[$index + 1] ?? ''))) {
                $inTable = false;
                continue;
            }
            if (!self::isTableRow($trimmed)) {
                $inTable = $inTable && str_contains($trimmed, '|') && 1 !== preg_match(self::BLOCK_START, $trimmed);
                if (!$inTable) {
                    continue;
                }
            }
            $rows[$index] = true;
        }

        return $rows;
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
     * ("iPhone costs …"), unless a bracket is still open. A part without a
     * letter or a digit is what closes the statement: the emphasis around
     * it, a quote mark, a bracket.
     */
    private static function continues(string $before, string $part, bool $afterMarkers): bool
    {
        if (1 !== preg_match(self::WORD, $part)) {
            return true;
        }

        return $afterMarkers
            ? 1 === preg_match(self::CONTINUES_AFTER_MARKERS, $part) || self::insideBracket($before)
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
