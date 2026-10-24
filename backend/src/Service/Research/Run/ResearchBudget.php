<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/**
 * What one run may still spend. Money is kept in millionths of a dollar so
 * 0.10 plus 0.20 stays 0.30. `canCall()` is the hard stop: no calls left,
 * the cost cap is reached, or the wall clock is up.
 */
final class ResearchBudget
{
    private int $searches = 0;
    private int $sourcesRead = 0;
    private int $rounds = 0;
    private int $calls = 0;
    private int $spentMicro = 0;
    private int $estimateMicro;
    private ?\DateTimeImmutable $asOf = null;

    public function __construct(
        private readonly ResearchDepth $depth,
        private readonly ResearchBudgetConfig $limits,
        private readonly \DateTimeImmutable $startedAt,
    ) {
        $this->estimateMicro = self::micro($limits->costCap);
    }

    public static function for(ResearchDepth $depth, ResearchBudgetConfig $config, ?\DateTimeImmutable $startedAt = null): self
    {
        return new self(
            $depth,
            $config,
            $startedAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    public function canSearch(): bool
    {
        return $this->searches < $this->limits->searches;
    }

    public function canRead(): bool
    {
        return $this->sourcesRead < $this->limits->sources;
    }

    public function canRound(): bool
    {
        return $this->rounds < $this->limits->rounds;
    }

    public function canCall(): bool
    {
        return $this->calls < $this->limits->maxModelCalls
            && $this->spentMicro < self::micro($this->limits->costCap)
            && $this->secondsLeft() > 0;
    }

    public function spend(string $counter, int $n = 1): void
    {
        if ($n < 1) {
            throw new \InvalidArgumentException('A budget spend is at least 1.');
        }

        match ($counter) {
            'searches' => $this->searches += $n,
            'sources' => $this->sourcesRead += $n,
            'rounds' => $this->rounds += $n,
            'calls' => $this->calls += $n,
            default => throw new \InvalidArgumentException('Unknown budget counter.'),
        };
    }

    public function addCost(float $amount): void
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException('A cost cannot be negative.');
        }

        $this->spentMicro += self::micro($amount);
    }

    public function setEstimate(float $amount): void
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException('A cost cannot be negative.');
        }

        $this->estimateMicro = self::micro($amount);
    }

    public function at(\DateTimeImmutable $now): void
    {
        $this->asOf = $now;
    }

    public function secondsLeft(): int
    {
        return max(0, $this->limits->wallSeconds - $this->elapsed());
    }

    public function calls(): int
    {
        return $this->calls;
    }

    public function callsLeft(): int
    {
        return max(0, $this->limits->maxModelCalls - $this->calls);
    }

    public function sourcesLeft(): int
    {
        return max(0, $this->limits->sources - $this->sourcesRead);
    }

    public function searchesLeft(): int
    {
        return max(0, $this->limits->searches - $this->searches);
    }

    public function extras(): ResearchBudgetExtras
    {
        return $this->limits->extras;
    }

    /**
     * The snapshot a screen can show: used, then the cap.
     *
     * @return array{searches: array{0: int, 1: int}, sources_read: array{0: int, 1: int}, rounds: array{0: int, 1: int}, seconds: array{0: int, 1: int}, cost: array{estimate: float, spent: float}}
     */
    public function toArray(): array
    {
        return [
            'searches' => [$this->searches, $this->limits->searches],
            'sources_read' => [$this->sourcesRead, $this->limits->sources],
            'rounds' => [$this->rounds, $this->limits->rounds],
            'seconds' => [$this->elapsed(), $this->limits->wallSeconds],
            'cost' => [
                'estimate' => self::usd($this->estimateMicro),
                'spent' => self::usd($this->spentMicro),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function export(): array
    {
        return [
            'depth' => $this->depth->value,
            'started_at' => $this->startedAt->format(\DateTimeInterface::ATOM),
            'searches' => $this->searches,
            'sources_read' => $this->sourcesRead,
            'rounds' => $this->rounds,
            'model_calls' => $this->calls,
            'spent_micro' => $this->spentMicro,
            'estimate_micro' => $this->estimateMicro,
            'limits' => $this->limits->export(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromExport(ResearchDepth $depth, array $data): self
    {
        if (isset($data['depth']) && $data['depth'] !== $depth->value) {
            throw new \InvalidArgumentException('Research budget does not match the run.');
        }
        $limits = $data['limits'] ?? null;
        if (!is_array($limits)) {
            throw new \InvalidArgumentException('A research budget is incomplete.');
        }
        if (!is_string($data['started_at'] ?? null) || '' === $data['started_at']) {
            throw new \InvalidArgumentException('A research budget is incomplete.');
        }
        $startedAt = new \DateTimeImmutable($data['started_at']);
        $budget = new self($depth, ResearchBudgetConfig::fromExport($limits), $startedAt);
        $budget->searches = self::nonNegative($data['searches'] ?? 0);
        $budget->sourcesRead = self::nonNegative($data['sources_read'] ?? 0);
        $budget->rounds = self::nonNegative($data['rounds'] ?? 0);
        $budget->calls = self::nonNegative($data['model_calls'] ?? 0);
        $budget->spentMicro = self::nonNegative($data['spent_micro'] ?? 0);
        $budget->estimateMicro = self::nonNegative($data['estimate_micro'] ?? self::micro($budget->limits->costCap));

        return $budget;
    }

    private function elapsed(): int
    {
        $now = $this->asOf ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return max(0, $now->getTimestamp() - $this->startedAt->getTimestamp());
    }

    private static function micro(float $usd): int
    {
        return (int) round($usd * 1_000_000);
    }

    private static function usd(int $micro): float
    {
        return round($micro / 1_000_000, 6);
    }

    private static function nonNegative(mixed $value): int
    {
        if (!is_numeric($value)) {
            return 0;
        }

        return max(0, (int) $value);
    }
}
