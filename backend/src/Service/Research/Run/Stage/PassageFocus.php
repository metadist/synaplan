<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

use App\Service\Context\ContextCondenser;
use App\Service\Research\Run\Ledger\Passage;

/**
 * Chooses which passages of a long source the reader sees. A page that fits
 * is sent whole. A longer one sends the passages that share the most words
 * with the goal, the points and their queries, each with the passage before
 * and after it, in page order, so menus at the top do not use up the budget.
 * Ids are kept, so a citation still points into the full text.
 */
final class PassageFocus
{
    private const MIN_TERM_CHARS = 3;

    /** A short passage is often a menu item; it needs two shared words to count on its own. */
    private const SHORT_PASSAGE_CHARS = 40;
    private const SHORT_PASSAGE_HITS = 2;
    private const TERM_SCORE = 2;
    private const NUMBER_SCORE = 1;

    /**
     * @param list<Passage> $passages
     * @param list<string>  $topics
     *
     * @return list<Passage>
     */
    public static function pick(array $passages, array $topics, int $budget): array
    {
        if (self::size($passages) <= $budget) {
            return $passages;
        }

        $scores = self::scores($passages, self::terms($topics));
        if ([] === $scores) {
            return self::head($passages, $budget);
        }

        $picked = [];
        $used = 0;
        foreach (array_keys($scores) as $index) {
            foreach ([0, -1, 1] as $offset) {
                $at = $index + $offset;
                if (!isset($passages[$at]) || isset($picked[$at])) {
                    continue;
                }
                $cost = self::cost($passages[$at]);
                if ($used + $cost > $budget) {
                    continue;
                }
                $picked[$at] = $passages[$at];
                $used += $cost;
            }
        }
        ksort($picked);

        return array_values($picked);
    }

    public static function cost(Passage $passage): int
    {
        return mb_strlen('['.$passage->id.'] '.$passage->text);
    }

    /**
     * @param list<string> $topics
     *
     * @return list<string>
     */
    private static function terms(array $topics): array
    {
        $terms = [];
        foreach ($topics as $topic) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($topic)) ?: [] as $word) {
                if (mb_strlen($word) >= self::MIN_TERM_CHARS && !isset(ContextCondenser::EXTRACT_STOP_WORDS[$word])) {
                    $terms[$word] = true;
                }
            }
        }

        $words = [];
        foreach (array_keys($terms) as $term) {
            // A year such as "2024" is stored as an int key, and str_contains rejects that.
            $words[] = (string) $term;
        }

        return $words;
    }

    /**
     * Passages that share enough terms, best first, ties in page order.
     *
     * @param list<Passage> $passages
     * @param list<string>  $terms
     *
     * @return array<int, int>
     */
    private static function scores(array $passages, array $terms): array
    {
        $scores = [];
        foreach ($passages as $index => $passage) {
            $lower = mb_strtolower($passage->text);
            $hits = 0;
            foreach ($terms as $term) {
                if (str_contains($lower, $term)) {
                    ++$hits;
                }
            }
            $needed = mb_strlen($passage->text) < self::SHORT_PASSAGE_CHARS ? self::SHORT_PASSAGE_HITS : 1;
            if ($hits >= $needed) {
                $scores[$index] = $hits * self::TERM_SCORE + (1 === preg_match('/\d/', $passage->text) ? self::NUMBER_SCORE : 0);
            }
        }
        uksort($scores, static fn (int $left, int $right): int => $scores[$right] <=> $scores[$left] ?: $left <=> $right);

        return $scores;
    }

    /**
     * @param list<Passage> $passages
     *
     * @return list<Passage>
     */
    private static function head(array $passages, int $budget): array
    {
        $sent = [];
        $used = 0;
        foreach ($passages as $passage) {
            $used += self::cost($passage);
            if ($used > $budget) {
                break;
            }
            $sent[] = $passage;
        }

        return $sent;
    }

    /**
     * @param list<Passage> $passages
     */
    private static function size(array $passages): int
    {
        return array_sum(array_map(self::cost(...), $passages));
    }
}
