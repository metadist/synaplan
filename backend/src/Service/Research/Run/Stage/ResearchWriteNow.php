<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

/**
 * Set by the person pressing Write now. The next step writes the report
 * from what was already found and does not search again.
 *
 * The check asks the store, so a press that arrives while a step is running
 * is seen before that step's next paid call.
 */
final class ResearchWriteNow
{
    /** @param (\Closure(): bool)|null $check */
    public function __construct(
        public bool $writing = false,
        private readonly ?\Closure $check = null,
    ) {
    }

    public function requested(): bool
    {
        if (!$this->writing && null !== $this->check) {
            $this->writing = ($this->check)();
        }

        return $this->writing;
    }
}
