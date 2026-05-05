<?php

declare(strict_types=1);

namespace App\Seed;

/**
 * Outcome of an idempotent seed run.
 *
 * Used by all App\Seed\* services so commands and the orchestration command
 * (app:seed) can present consistent statistics without coupling to internal SQL.
 */
final readonly class SeedResult
{
    public function __construct(
        public string $label,
        public int $inserted,
        public int $updated = 0,
        public int $skipped = 0,
        /**
         * Rows the seeder intentionally left untouched because they look
         * operator-customised (e.g. the admin edited prices via the UI and we
         * detected the divergence via a content fingerprint). Distinct from
         * `$skipped`, which means "row already in sync, nothing to do".
         */
        public int $preserved = 0,
    ) {
    }

    public function total(): int
    {
        return $this->inserted + $this->updated + $this->skipped + $this->preserved;
    }
}
