<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/**
 * What one run may still spend. Money is kept in millionths of a dollar so
 * 0.10 plus 0.20 stays 0.30. `canCall()` is the hard stop: no calls left,
 * the cost cap is reached, or the wall clock is up.
 *
 * The wall clock counts only time a step works. A saved run stops the
 * clock, and the next step starts it again, so waiting for a worker or for
 * the person costs nothing.
 */
final class ResearchBudget
{
    /** The limits that can end the gathering of one run, in the order they are judged. */
    public const LIMIT_CALLS = 'calls';
    public const LIMIT_WALL = 'wall';
    public const LIMIT_COST = 'cost';
    public const LIMIT_ROUNDS = 'rounds';
    public const LIMIT_GATHER = 'gather';

    /** @var list<string> */
    public const LIMIT_REASONS = [self::LIMIT_CALLS, self::LIMIT_WALL, self::LIMIT_COST, self::LIMIT_ROUNDS, self::LIMIT_GATHER];

    private int $searches = 0;
    private int $sourcesRead = 0;
    private int $rounds = 0;
    private int $calls = 0;
    private int $spentMicro = 0;
    private int $estimateMicro;
    private int $workedSeconds = 0;
    private ?\DateTimeImmutable $runningSince;
    private ?\DateTimeImmutable $asOf = null;

    public function __construct(
        private readonly ResearchDepth $depth,
        private ResearchBudgetConfig $limits,
        private readonly \DateTimeImmutable $startedAt,
    ) {
        $this->estimateMicro = self::micro($limits->costCap);
        $this->runningSince = $startedAt;
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

    /**
     * Which limit of this depth is spent, if any. The hard stop comes
     * first, in the order the loop rules judge it. A rounds cap of zero
     * is how a quick run is built, so it never counts as spent; searches
     * and sources count only when both are gone, because either one
     * alone can still feed a round.
     */
    public function exhaustedReason(): ?string
    {
        if ($this->calls >= $this->limits->maxModelCalls) {
            return self::LIMIT_CALLS;
        }
        if (0 === $this->secondsLeft()) {
            return self::LIMIT_WALL;
        }
        if ($this->spentMicro >= self::micro($this->limits->costCap)) {
            return self::LIMIT_COST;
        }
        if ($this->limits->rounds > 0 && $this->rounds >= $this->limits->rounds) {
            return self::LIMIT_ROUNDS;
        }
        if (!$this->canSearch() && !$this->canRead()) {
            return self::LIMIT_GATHER;
        }

        return null;
    }

    /** The cap of one limit as a person reads it: minutes, dollars, or a count. Empty for the gather pair. */
    public function limitValue(string $reason): string
    {
        return match ($reason) {
            self::LIMIT_WALL => (string) max(1, (int) ceil($this->limits->wallSeconds / 60)),
            self::LIMIT_COST => rtrim(rtrim(number_format($this->limits->costCap, 2, '.', ''), '0'), '.'),
            self::LIMIT_CALLS => (string) $this->limits->maxModelCalls,
            self::LIMIT_ROUNDS => (string) $this->limits->rounds,
            default => '',
        };
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

    public function startStep(\DateTimeImmutable $now): void
    {
        $this->workedSeconds = $this->elapsed();
        $this->runningSince = $now;
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

    public function limits(): ResearchBudgetConfig
    {
        return $this->limits;
    }

    /**
     * Lower the limits to another row. Spent counters stay, so a run that
     * already used more than the new cap simply has nothing left. The cost
     * estimate follows the new cap when it was still the old one.
     */
    public function shrinkTo(ResearchBudgetConfig $ceiling): void
    {
        $oldCap = self::micro($this->limits->costCap);
        $this->limits = $this->limits->cappedAt($ceiling);
        if ($this->estimateMicro === $oldCap) {
            $this->estimateMicro = self::micro($this->limits->costCap);
        }
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
            'worked_seconds' => $this->elapsed(),
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
        $budget->runningSince = null;
        $budget->workedSeconds = self::nonNegative($data['worked_seconds'] ?? 0);
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
        if (null === $this->runningSince) {
            return $this->workedSeconds;
        }
        $now = $this->asOf ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->workedSeconds + max(0, $now->getTimestamp() - $this->runningSince->getTimestamp());
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
