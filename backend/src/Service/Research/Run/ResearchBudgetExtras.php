<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/**
 * The counts that do not fit on the main budget row. A run keeps the numbers
 * it started with, even if an admin changes the defaults later.
 */
final readonly class ResearchBudgetExtras
{
    public function __construct(
        public int $resultsPerSearch,
        public int $fetchParallel,
        public int $readParallel,
        public int $questions,
        public int $reportWords,
        public int $maxActions,
        public ?int $pointsMin = null,
        public ?int $pointsMax = null,
    ) {
    }

    /**
     * @return array<string, int|null>
     */
    public function export(): array
    {
        return [
            'results_per_search' => $this->resultsPerSearch,
            'fetch_parallel' => $this->fetchParallel,
            'read_parallel' => $this->readParallel,
            'questions' => $this->questions,
            'report_words' => $this->reportWords,
            'max_actions' => $this->maxActions,
            'points_min' => $this->pointsMin,
            'points_max' => $this->pointsMax,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromExport(array $data): self
    {
        return new self(
            self::whole($data, 'results_per_search', 1),
            self::whole($data, 'fetch_parallel', 1),
            self::whole($data, 'read_parallel', 1),
            self::whole($data, 'questions', 0),
            self::whole($data, 'report_words', 100),
            self::whole($data, 'max_actions', 0),
            self::optional($data, 'points_min'),
            self::optional($data, 'points_max'),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function whole(array $data, string $key, int $min): int
    {
        $value = $data[$key] ?? $min;
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException('A research budget is incomplete.');
        }

        return max($min, (int) $value);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function optional(array $data, string $key): ?int
    {
        if (!isset($data[$key]) || !is_numeric($data[$key])) {
            return null;
        }

        return (int) $data[$key];
    }
}
