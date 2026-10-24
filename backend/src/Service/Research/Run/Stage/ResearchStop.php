<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

/**
 * Set by the person pressing Stop. The next paid call must not start.
 *
 * The check asks the store, so a Stop that arrives while a unit is running is
 * seen before that unit's next paid call, not only at the next unit.
 */
final class ResearchStop
{
    /** @param (\Closure(): bool)|null $check */
    public function __construct(
        public bool $stopped = false,
        private readonly ?\Closure $check = null,
    ) {
    }

    public function requested(): bool
    {
        if (!$this->stopped && null !== $this->check) {
            $this->stopped = ($this->check)();
        }

        return $this->stopped;
    }
}
