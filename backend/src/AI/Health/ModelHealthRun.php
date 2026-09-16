<?php

declare(strict_types=1);

namespace App\AI\Health;

/**
 * Everything one health-check run produced, for the console output and the
 * admin endpoint.
 */
final readonly class ModelHealthRun
{
    /**
     * @param list<ModelHealthVerdict> $verdicts
     * @param array<string, string>    $skippedProviders provider => why it was skipped
     * @param list<ModelHealthAlert>   $alertsRaised
     * @param list<ModelHealthAlert>   $alertsResolved
     */
    public function __construct(
        public array $verdicts,
        public array $skippedProviders,
        public array $alertsRaised,
        public array $alertsResolved,
        public bool $dryRun,
    ) {
    }

    /**
     * @return list<ModelHealthVerdict>
     */
    public function withState(ModelHealthState $state): array
    {
        return array_values(array_filter($this->verdicts, static fn (ModelHealthVerdict $v): bool => $v->state === $state));
    }

    public function countWithState(ModelHealthState $state): int
    {
        return count($this->withState($state));
    }

    /**
     * @return list<ModelHealthVerdict>
     */
    public function autoDisabled(): array
    {
        return array_values(array_filter($this->verdicts, static fn (ModelHealthVerdict $v): bool => $v->autoDisabled));
    }

    /**
     * @return list<ModelHealthVerdict>
     */
    public function reEnabled(): array
    {
        return array_values(array_filter($this->verdicts, static fn (ModelHealthVerdict $v): bool => $v->reEnabled));
    }
}
