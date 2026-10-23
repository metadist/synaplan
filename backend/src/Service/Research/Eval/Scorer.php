<?php

declare(strict_types=1);

namespace App\Service\Research\Eval;

/**
 * Deterministic scoring. Match strings and trap phrases need no model.
 * A gold row without a match string is left for a judge.
 */
final readonly class Scorer
{
    /**
     * @param list<string> $needles every needle must occur
     */
    public function containsAll(string $report, array $needles): bool
    {
        if ([] === $needles) {
            return false;
        }
        $haystack = $this->normalise($report);
        foreach ($needles as $needle) {
            if (!$this->found($haystack, $needle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Lower case, collapsed whitespace, thousands separators removed.
     *
     * "1,500", "1.500" and "1 500" become "1500". A decimal such as "3,5"
     * stays, because the group after the separator is not three digits.
     */
    public function normalise(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace_callback(
            '/\d{1,3}(?:[.,\s]\d{3})+/u',
            static fn (array $match): string => preg_replace('/[.,\s]/', '', $match[0]) ?? $match[0],
            $text,
        ) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @return array{recall: float|null, hits: int, total: int, abstained: bool}
     */
    public function score(CorpusItem $item, string $report): array
    {
        $hits = 0;
        $total = 0;
        foreach ($item->facts->entries as $entry) {
            if ([] === $entry['match']) {
                continue;
            }
            ++$total;
            if ($this->containsAll($report, $entry['match'])) {
                ++$hits;
            }
        }

        return [
            'recall' => $total > 0 ? (float) $hits / $total : null,
            'hits' => $hits,
            'total' => $total,
            'abstained' => [] !== $item->facts->forbidden && !$this->containsAny($report, $item->facts->forbidden),
        ];
    }

    /**
     * @param list<string> $needles
     */
    private function containsAny(string $report, array $needles): bool
    {
        $haystack = $this->normalise($report);
        foreach ($needles as $needle) {
            if ($this->found($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function found(string $haystack, string $needle): bool
    {
        $needle = $this->normalise($needle);
        if (1 === preg_match('/^\d+$/', $needle)) {
            return 1 === preg_match('/(?<!\d)'.preg_quote($needle, '/').'(?!\d)/', $haystack);
        }

        return str_contains($haystack, $needle);
    }
}
