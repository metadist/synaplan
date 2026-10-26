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
    public const UNKNOWN_ID = 'unknown_id';
    public const NUMBER = 'number';
    public const DATE = 'date';
    public const NAME = 'name';
    public const UNCITED = 'uncited';

    /**
     * Words that open a capitalised run without being part of the name:
     * articles, and the prepositions and pronouns that start a sentence,
     * in the five languages of the app.
     *
     * @var list<string>
     */
    private const LEADING_WORDS = [
        'the', 'a', 'an', 'this', 'that', 'these', 'those', 'in', 'on', 'at', 'since', 'after', 'before',
        'by', 'for', 'from', 'with', 'of', 'to', 'today', 'according',
        'der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'einen', 'einem', 'einer', 'im', 'am',
        'auf', 'aus', 'bei', 'mit', 'nach', 'seit', 'von', 'vor', 'zu', 'zum', 'zur', 'für', 'über',
        'unter', 'laut', 'gemäß', 'heute', 'dieser', 'diese', 'dieses', 'diesen',
        'le', 'la', 'les', 'un', 'une', 'du', 'de', 'dans', 'en', 'à', 'au', 'aux', 'depuis',
        'selon', 'pour', 'avec', 'sur', 'ce', 'cette', 'ces',
        'el', 'los', 'las', 'una', 'unos', 'unas', 'del', 'desde', 'según', 'para', 'con', 'por',
        'sobre', 'este', 'esta', 'estos', 'estas',
        'bu', 'şu', 'bir', 've', 'ile', 'göre',
    ];

    /**
     * @param array<string, string> $passages evidence id to the text it rests on
     *
     * @return list<array{statement: string, reason: string}>
     */
    public function check(string $report, array $passages): array
    {
        $issues = [];
        foreach (DraftSentences::split($report) as $sentence) {
            $ids = $this->ids($sentence);
            $bare = $this->bare($sentence);
            $reason = [] === $ids
                ? ($this->statesFact($bare) ? self::UNCITED : null)
                : $this->reason($bare, $ids, $passages);
            if (null !== $reason) {
                $issues[] = ['statement' => $bare, 'reason' => $reason];
            }
        }

        return $issues;
    }

    /**
     * A revision may drop or shorten. It may not add a problem the draft
     * before it did not have: an uncited fact, or a cited one the evidence
     * does not back.
     *
     * @param array<string, string> $passages
     */
    public function revisionAddsIssue(string $before, string $after, array $passages): bool
    {
        $had = [];
        foreach ($this->check($before, $passages) as $issue) {
            $had[$this->issueKey($issue)] = true;
        }
        foreach ($this->check($after, $passages) as $issue) {
            if (!isset($had[$this->issueKey($issue)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{statement: string, reason: string} $issue
     */
    private function issueKey(array $issue): string
    {
        return $issue['reason'].':'.PassageText::normalise($issue['statement']);
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
                return self::UNKNOWN_ID;
            }
            $texts[] = $passages[$id];
        }
        $support = implode("\n", $texts);
        if (!$this->covered(PassageText::numbersIn(PassageText::withoutDates($statement)), PassageText::numbersIn($support))) {
            return self::NUMBER;
        }
        if (!$this->covered(PassageText::datesIn($statement), PassageText::datesIn($support))) {
            return self::DATE;
        }
        $haystack = PassageText::normalise($support);
        foreach ($this->names($statement) as $name) {
            if (1 !== preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u', $haystack)) {
                return self::NAME;
            }
        }

        return null;
    }

    /**
     * @param list<string> $wanted
     * @param list<string> $have
     */
    private function covered(array $wanted, array $have): bool
    {
        return [] === array_diff($wanted, $have);
    }

    private function statesFact(string $sentence): bool
    {
        return [] !== PassageText::numbersIn(PassageText::withoutDates($sentence))
            || [] !== PassageText::datesIn($sentence)
            || [] !== $this->names($sentence);
    }

    /**
     * A proper name is two capitalised words, or one word with an internal
     * capital such as SearXNG. A single capitalised word is not one: German
     * writes every noun that way. An all-caps acronym such as EUR is not one
     * either. Names come back normalised, as PassageText writes them.
     *
     * @return list<string>
     */
    private function names(string $text): array
    {
        $text = PassageText::withoutDates($text);
        $found = [];
        if (preg_match_all('/\b\p{Lu}\p{L}*(?:\s+\p{Lu}\p{L}*)+\b/u', $text, $rows) > 0) {
            foreach ($rows[0] as $name) {
                $words = preg_split('/\s+/u', $name) ?: [];
                while (count($words) > 1 && $this->isLeadingWord($words[0])) {
                    array_shift($words);
                }
                while (count($words) > 1 && PassageText::isMonth($words[count($words) - 1])) {
                    array_pop($words);
                }
                if (count($words) > 1) {
                    $found[] = PassageText::normalise(implode(' ', $words));
                }
            }
        }
        if (preg_match_all('/\b\p{Lu}\p{Ll}+\p{Lu}\p{L}*\b/u', $text, $rows) > 0) {
            foreach ($rows[0] as $name) {
                $found[] = PassageText::normalise($name);
            }
        }

        return array_values(array_unique($found));
    }

    private function isLeadingWord(string $word): bool
    {
        $word = mb_strtolower($word);

        return in_array($word, self::LEADING_WORDS, true) || PassageText::isMonth($word);
    }

    /**
     * @return list<string>
     */
    private function ids(string $sentence): array
    {
        preg_match_all(DraftSentences::MARKER, $sentence, $markers);
        preg_match_all('/e\d+/i', implode(' ', $markers[0]), $ids);

        return array_values(array_unique(array_map('strtolower', $ids[0])));
    }

    private function bare(string $sentence): string
    {
        $bare = preg_replace(DraftSentences::MARKER, '', $sentence) ?? $sentence;

        return trim((string) preg_replace('/\s+/u', ' ', $bare));
    }
}
