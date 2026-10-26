<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Research\Run\Ledger\PassageText;

/**
 * The free half of the fact-check. A cited statement must point at evidence
 * that exists, and its numbers, dates and proper names must be in that
 * evidence. A sentence that states a fact and cites nothing is flagged.
 * Nothing here calls a model, and a run does not use this yet.
 */
final readonly class DraftCheck
{
    private const MARKER = '/\[\s*e\d+(?:\s*,\s*e\d+)*\s*\]/i';
    private const ABBREVIATION_END = '/(?:\b(?:Art|Abs|Buchst|Bst|Nr|Ziff|lit|Kap|Rn|Anh|bzw|ca|vgl|ggf|Mio|Mrd|etc|vs|No|Vol|Sec|Ch|pp|ff|Dr|Prof|St|Mr|Mrs|Ms|Mme|cf|al)|\b\p{L}|(?<![\d.])\d{1,2})\.$/u';
    private const SOURCES = '/^(?:#{1,6}\s*)?(?:sources?|references?|quellen|fuentes|r[ée]f[ée]rences)\b/iu';

    /** @var list<string> */
    private const ARTICLES = ['the', 'a', 'an', 'der', 'die', 'das', 'ein', 'eine', 'le', 'la', 'les', 'el', 'los', 'las', 'un', 'une'];

    /**
     * @param array<string, string> $passages evidence id to the text it rests on
     *
     * @return list<array{statement: string, reason: string}>
     */
    public function check(string $report, array $passages): array
    {
        $issues = [];
        foreach ($this->sentences($report) as $sentence) {
            $ids = $this->ids($sentence);
            $bare = $this->bare($sentence);
            if ([] === $ids) {
                if ($this->statesFact($bare)) {
                    $issues[] = ['statement' => $bare, 'reason' => 'uncited'];
                }
                continue;
            }
            $reason = $this->reason($bare, $ids, $passages);
            if (null !== $reason) {
                $issues[] = ['statement' => $bare, 'reason' => $reason];
            }
        }

        return $issues;
    }

    /**
     * A revision may drop or shorten. It may not add a fact that cites nothing.
     *
     * @param array<string, string> $passages
     */
    public function revisionAddsFact(string $before, string $after, array $passages): bool
    {
        $had = [];
        foreach ($this->check($before, $passages) as $issue) {
            if ('uncited' === $issue['reason']) {
                $had[PassageText::normalise($issue['statement'])] = true;
            }
        }
        foreach ($this->check($after, $passages) as $issue) {
            if ('uncited' === $issue['reason'] && !isset($had[PassageText::normalise($issue['statement'])])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string>          $ids
     * @param array<string, string> $passages
     */
    private function reason(string $statement, array $ids, array $passages): ?string
    {
        $texts = [];
        foreach ($ids as $id) {
            if (!isset($passages[$id]) || '' === trim($passages[$id])) {
                return 'unknown_id';
            }
            $texts[] = $passages[$id];
        }
        $support = implode("\n", $texts);
        if (!$this->covered(PassageText::numbersIn(PassageText::withoutDates($statement)), PassageText::numbersIn(PassageText::withoutDates($support)))) {
            return 'number';
        }
        if (!$this->covered(PassageText::datesIn($statement), PassageText::datesIn($support))) {
            return 'date';
        }
        if (!$this->covered($this->names($statement), $this->names($support))) {
            return 'name';
        }

        return null;
    }

    /**
     * @param list<string> $wanted
     * @param list<string> $have
     */
    private function covered(array $wanted, array $have): bool
    {
        foreach ($wanted as $item) {
            if (!in_array($item, $have, true)) {
                return false;
            }
        }

        return true;
    }

    private function statesFact(string $sentence): bool
    {
        return [] !== PassageText::numbersIn(PassageText::withoutDates($sentence))
            || [] !== PassageText::datesIn($sentence)
            || [] !== $this->names($sentence);
    }

    /**
     * A proper name is two capitalised words, or one word with an internal
     * capital. A single capitalised word is not one: German writes every
     * noun that way.
     *
     * @return list<string>
     */
    private function names(string $text): array
    {
        $found = [];
        if (preg_match_all('/\b\p{Lu}\p{L}*(?:\s+\p{Lu}\p{L}*)+\b/u', $text, $rows) > 0) {
            foreach ($rows[0] as $name) {
                $words = preg_split('/\s+/u', $name) ?: [];
                while (count($words) > 1 && in_array(mb_strtolower($words[0]), self::ARTICLES, true)) {
                    array_shift($words);
                }
                if (count($words) > 1) {
                    $found[] = mb_strtolower(implode(' ', $words));
                }
            }
        }
        if (preg_match_all('/\b\p{Lu}\p{Ll}+\p{Lu}\p{L}*\b/u', $text, $rows) > 0) {
            foreach ($rows[0] as $name) {
                $found[] = mb_strtolower($name);
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @return list<string>
     */
    private function ids(string $sentence): array
    {
        preg_match_all('/e\d+/i', implode(' ', $this->markers($sentence)), $ids);

        return array_values(array_unique(array_map('strtolower', $ids[0])));
    }

    /**
     * @return list<string>
     */
    private function markers(string $sentence): array
    {
        preg_match_all(self::MARKER, $sentence, $markers);

        return $markers[0];
    }

    private function bare(string $sentence): string
    {
        $bare = preg_replace(self::MARKER, '', $sentence) ?? $sentence;

        return trim((string) preg_replace('/\s+/u', ' ', $bare));
    }

    /**
     * @return list<string>
     */
    private function sentences(string $report): array
    {
        $out = [];
        $inSources = false;
        foreach (preg_split("/\r\n|\r|\n/u", $report) ?: [] as $line) {
            $trimmed = trim($line);
            if (str_starts_with($trimmed, '#')) {
                $inSources = 1 === preg_match(self::SOURCES, $trimmed);
                continue;
            }
            if ($inSources || '' === $trimmed) {
                continue;
            }
            $parts = preg_split('/(?<=[.!?])\s+/u', $trimmed, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $lineStart = count($out);
            foreach ($parts as $part) {
                $markersOnly = '' === trim((string) preg_replace(self::MARKER, '', $part));
                $afterAbbreviation = count($out) > $lineStart && 1 === preg_match(self::ABBREVIATION_END, $out[array_key_last($out)]);
                if (($markersOnly || $afterAbbreviation) && [] !== $out) {
                    $out[array_key_last($out)] .= ' '.$part;
                    continue;
                }
                $out[] = $part;
            }
        }

        return $out;
    }
}
