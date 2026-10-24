<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/**
 * Which providers a run may call.
 *
 * A web run uses the person's group allow-list (applied by the lookup).
 * A desktop run may only use the providers the project already uses.
 */
final readonly class ResearchWorld
{
    /**
     * @param list<string> $providers service names; ignored unless $desktop
     */
    public function __construct(
        public bool $desktop,
        public array $providers = [],
    ) {
    }

    public function allows(string $provider): bool
    {
        if (!$this->desktop) {
            return true;
        }

        $want = strtolower($provider);
        foreach ($this->providers as $allowed) {
            if (strtolower($allowed) === $want) {
                return true;
            }
        }

        return false;
    }
}
