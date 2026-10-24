<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/**
 * How many input tokens one call of a role may use.
 *
 * min(role cap, 60 % of the model's context window minus the role's output cap).
 * A model with no window keeps the role cap. A window that cannot hold the
 * output cap leaves no input.
 */
final readonly class ResearchInputBudget
{
    /** @var array<string, int> */
    private const INPUT_CAP = [
        'sorter' => 4000,
        'reader' => 60000,
        'lead' => 80000,
        'writer' => 60000,
        'checker' => 40000,
    ];

    /** @var array<string, int> */
    private const OUTPUT_CAP = [
        'sorter' => 1000,
        'reader' => 1500,
        'lead' => 4000,
        'writer' => 8000,
        'checker' => 3000,
    ];

    public function tokens(string $role, ?int $contextWindow): int
    {
        $inputCap = self::INPUT_CAP[$role] ?? 0;
        if (null === $contextWindow || $contextWindow < 1) {
            return $inputCap;
        }

        $fromWindow = intdiv($contextWindow * 60, 100) - (self::OUTPUT_CAP[$role] ?? 0);
        if ($fromWindow < 1) {
            return 0;
        }

        return min($inputCap, $fromWindow);
    }

    public function inputCap(string $role): int
    {
        return self::INPUT_CAP[$role] ?? 0;
    }
}
