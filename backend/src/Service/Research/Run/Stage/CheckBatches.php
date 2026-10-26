<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

/**
 * Splits the statements the checker reads so one call fits its input budget.
 * A single statement is never split, even when it is longer than the budget.
 */
final class CheckBatches
{
    /**
     * @param list<array{id: string, sentence: string, bare: string, passages: string}> $items
     *
     * @return list<list<array{id: string, sentence: string, bare: string, passages: string}>>
     */
    public function groups(array $items, int $chars): array
    {
        $groups = [];
        $current = [];
        $size = 0;
        foreach ($items as $item) {
            $cost = mb_strlen($item['bare']) + mb_strlen($item['passages']) + 32;
            if ([] !== $current && $size + $cost > $chars) {
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
