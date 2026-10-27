<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/**
 * One save of the research tab, checked as a whole before anything is stored.
 * A value that is left out keeps what is stored; a value outside its range is
 * refused with the field it belongs to instead of being bent into range.
 */
final readonly class ResearchSettingsInput
{
    /**
     * @param array<string, array{searches: ?int, sources: ?int, minutes: ?int, costCap: ?float}> $budgets keyed by depth
     */
    private function __construct(
        public ?bool $enabled,
        public ?int $maxActiveRuns,
        public ?int $maxActiveThorough,
        public array $budgets,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromBody(array $body): self
    {
        $enabled = null;
        if (array_key_exists('enabled', $body)) {
            if (!is_bool($body['enabled'])) {
                throw new ResearchRejected(400, 'invalid', 'enabled', 'Choose on or off.');
            }
            $enabled = $body['enabled'];
        }

        $limits = self::section($body, 'limits');
        $budgets = [];
        $sentBudgets = self::section($body, 'budgets');
        foreach (ResearchDepth::cases() as $depth) {
            $row = self::section($sentBudgets, $depth->value);
            if ([] === $row) {
                continue;
            }
            $prefix = 'budgets.'.$depth->value.'.';
            $budgets[$depth->value] = [
                'searches' => self::whole($row, 'searches', 0, ResearchBudgetConfig::MAX_SEARCHES, $prefix),
                'sources' => self::whole($row, 'sources', ResearchBudgetConfig::MIN_SOURCES, ResearchBudgetConfig::MAX_SOURCES, $prefix),
                'minutes' => self::whole($row, 'minutes', ResearchBudgetConfig::MIN_MINUTES, ResearchBudgetConfig::MAX_MINUTES, $prefix),
                'costCap' => self::amount($row, 'costCap', ResearchBudgetConfig::MAX_COST_CAP, $prefix),
            ];
        }

        [$runsMin, $runsMax] = ResearchConfig::ACTIVE_RUNS_RANGE;
        [$thoroughMin, $thoroughMax] = ResearchConfig::ACTIVE_THOROUGH_RANGE;

        return new self(
            $enabled,
            self::whole($limits, 'maxActiveRuns', $runsMin, $runsMax, 'limits.'),
            self::whole($limits, 'maxActiveThorough', $thoroughMin, $thoroughMax, 'limits.'),
            $budgets,
        );
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private static function section(array $body, string $key): array
    {
        if (!array_key_exists($key, $body) || null === $body[$key]) {
            return [];
        }
        if (!is_array($body[$key])) {
            throw new ResearchRejected(400, 'invalid', $key, 'Send these settings as one object.');
        }

        return $body[$key];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function whole(array $row, string $key, int $min, int $max, string $prefix): ?int
    {
        if (!array_key_exists($key, $row) || null === $row[$key]) {
            return null;
        }
        $value = $row[$key];
        if (is_string($value) && 1 === preg_match('/^\d+$/', $value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new ResearchRejected(400, 'out_of_range', $prefix.$key, sprintf('Enter a whole number from %d to %d.', $min, $max));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function amount(array $row, string $key, float $max, string $prefix): ?float
    {
        if (!array_key_exists($key, $row) || null === $row[$key]) {
            return null;
        }
        $value = $row[$key];
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            throw new ResearchRejected(400, 'out_of_range', $prefix.$key, sprintf('Enter an amount from 0 to %.2f.', $max));
        }
        $value = (float) $value;
        if (!is_finite($value) || $value < 0.0 || $value > $max) {
            throw new ResearchRejected(400, 'out_of_range', $prefix.$key, sprintf('Enter an amount from 0 to %.2f.', $max));
        }

        return round($value, 2);
    }
}
