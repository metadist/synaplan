<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/**
 * Starting limits for one depth. The numbers are the plan's hypotheses until
 * a later bake-off replaces them. They are not read from the database yet.
 */
final readonly class ResearchBudgetConfig
{
    public function __construct(
        public int $searches,
        public int $sources,
        public int $rounds,
        public int $wallSeconds,
        public float $costCap,
        public int $maxModelCalls,
        public ResearchBudgetExtras $extras,
    ) {
    }

    public static function defaults(ResearchDepth $depth): self
    {
        return match ($depth) {
            ResearchDepth::Quick => self::quick(),
            ResearchDepth::Standard => self::standard(),
            ResearchDepth::Thorough => self::thorough(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function export(): array
    {
        return [
            'searches' => $this->searches,
            'sources' => $this->sources,
            'rounds' => $this->rounds,
            'wall_seconds' => $this->wallSeconds,
            'cost_cap' => $this->costCap,
            'max_model_calls' => $this->maxModelCalls,
            'extras' => $this->extras->export(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromExport(array $data): self
    {
        foreach (['searches', 'sources', 'rounds', 'wall_seconds', 'cost_cap', 'max_model_calls'] as $key) {
            if (!isset($data[$key]) || !is_numeric($data[$key])) {
                throw new \InvalidArgumentException('A research budget is incomplete.');
            }
        }
        $extras = $data['extras'] ?? [];
        if (!is_array($extras)) {
            throw new \InvalidArgumentException('A research budget is incomplete.');
        }

        return new self(
            max(0, (int) $data['searches']),
            max(1, (int) $data['sources']),
            max(0, (int) $data['rounds']),
            max(1, (int) $data['wall_seconds']),
            max(0.0, (float) $data['cost_cap']),
            max(1, (int) $data['max_model_calls']),
            ResearchBudgetExtras::fromExport($extras),
        );
    }

    private static function quick(): self
    {
        return new self(2, 4, 0, 60, 0.10, 6, new ResearchBudgetExtras(8, 4, 4, 0, 300, 0));
    }

    private static function standard(): self
    {
        return new self(10, 15, 3, 360, 1.00, 60, new ResearchBudgetExtras(10, 4, 6, 1, 1500, 6, 3, 5));
    }

    private static function thorough(): self
    {
        return new self(30, 40, 8, 1200, 5.00, 200, new ResearchBudgetExtras(10, 6, 8, 3, 6000, 10, 4, 7));
    }
}
