<?php

declare(strict_types=1);

namespace App\Service\SelfAware;

/**
 * Snapshot of what this installation can do for one user, right now.
 *
 * @phpstan-type FactList list<CapabilityFact>
 */
final readonly class CapabilityReport
{
    /**
     * @param list<CapabilityFact> $facts
     */
    public function __construct(
        public array $facts,
        public string $version,
        public bool $billingEnabled,
        public bool $isAdmin,
    ) {
    }

    /**
     * @return list<CapabilityFact>
     */
    public function byState(CapabilityState $state): array
    {
        $matched = [];
        foreach ($this->facts as $fact) {
            if ($fact->state === $state) {
                $matched[] = $fact;
            }
        }

        return $matched;
    }

    public function fact(string $id): ?CapabilityFact
    {
        foreach ($this->facts as $fact) {
            if ($fact->id === $id) {
                return $fact;
            }
        }

        return null;
    }
}
