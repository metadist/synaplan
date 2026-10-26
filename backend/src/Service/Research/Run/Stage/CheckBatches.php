<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

/**
 * Splits the statements the checker reads so one call fits its input budget,
 * and its verdicts fit the checker's output cap. A single statement is never
 * split, even when it is longer than the budget.
 */
final class CheckBatches
{
    /** About 60 output tokens per verdict keeps a batch well under the 3000-token output cap. */
    public const MAX_STATEMENTS = 30;

    private const OVERHEAD_CHARS = 80;

    /**
     * @param list<array{id: string, sentence: string, bare: string, passages: string}> $items
     *
     * @return list<list<array{id: string, sentence: string, bare: string, passages: string}>>
     */
    public function groups(array $items, int $chars, int $maxStatements = self::MAX_STATEMENTS): array
    {
        $groups = [];
        $current = [];
        $size = 0;
        foreach ($items as $item) {
            $cost = mb_strlen($item['bare']) + mb_strlen($item['passages']) + self::OVERHEAD_CHARS;
            if ([] !== $current && ($size + $cost > $chars || count($current) >= $maxStatements)) {
                $groups[] = $current;
                $current = [];
                $size = 0;
            }
            $current[] = $item;
            $size += $cost;
        }
        if ([] !== $current) {
            $groups[] = $current;
        }

        return $groups;
    }
}
