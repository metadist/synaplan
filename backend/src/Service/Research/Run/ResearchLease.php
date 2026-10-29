<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/**
 * The claim one research step holds while it runs.
 *
 * Renewing slides that same lease forward. It does not grant a longer one.
 * A call that cannot renew while it waits has to return inside waitMs(), so
 * the checkpoint still finds the claim held.
 */
final readonly class ResearchLease
{
    /** Left after a call returns, so the step can still checkpoint. */
    public const MARGIN_MS = 15_000;

    public function __construct(
        private ResearchStateStore $store,
        private string $runId,
        private int $seq,
        private string $token,
        private int $leaseMs,
    ) {
    }

    /** False when this token no longer holds the step. */
    public function renew(): bool
    {
        return $this->store->renew($this->runId, $this->seq, $this->token, $this->leaseMs);
    }

    /** How long a call may block before it renews again or returns. */
    public function waitMs(): int
    {
        return max(self::MARGIN_MS, $this->leaseMs - self::MARGIN_MS);
    }
}
