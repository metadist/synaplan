<?php

declare(strict_types=1);

namespace App\AI\Pipeline;

/**
 * Cooperative total-duration deadline for a blocking {@see AiCallHookInterface}
 * (`03_contracts.md` §1.4, §1.7.4).
 *
 * One deadline is created from `hrtime(true)` and shared across every retry or
 * nested call the hook makes — never reset.
 */
final readonly class AiCallDeadline
{
    public function __construct(
        public int $startedHrtimeNs,
        public int $budgetMs,
    ) {
    }

    /**
     * @param (\Closure(): int)|null $clockNs
     */
    public static function start(int $budgetMs, ?\Closure $clockNs = null, ?self $inherited = null): self
    {
        $now = null !== $clockNs ? $clockNs() : hrtime(true);
        $effectiveBudgetMs = max(1, $budgetMs);
        if (null !== $inherited) {
            $inheritedRemainingMs = (int) floor($inherited->remainingSeconds($clockNs) * 1000.0);
            $effectiveBudgetMs = max(1, min($effectiveBudgetMs, $inheritedRemainingMs));
        }

        return new self($now, $effectiveBudgetMs);
    }

    /**
     * @param (\Closure(): int)|null $clockNs
     */
    public function remainingSeconds(?\Closure $clockNs = null): float
    {
        $now = null !== $clockNs ? $clockNs() : hrtime(true);
        $elapsedMs = ($now - $this->startedHrtimeNs) / 1_000_000.0;

        return max(0.001, ($this->budgetMs - $elapsedMs) / 1000.0);
    }

    /**
     * @param (\Closure(): int)|null $clockNs
     */
    public function isOverBudget(?\Closure $clockNs = null): bool
    {
        $now = null !== $clockNs ? $clockNs() : hrtime(true);

        return (($now - $this->startedHrtimeNs) / 1_000_000.0) > $this->budgetMs;
    }

    /**
     * @param (\Closure(): int)|null $clockNs
     *
     * @return array{timeout: float, max_duration: float}
     */
    public function toHttpClientOptions(?\Closure $clockNs = null): array
    {
        $remaining = $this->remainingSeconds($clockNs);

        return ['timeout' => $remaining, 'max_duration' => $remaining];
    }
}
