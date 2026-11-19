<?php

declare(strict_types=1);

namespace App\Service\Run;

/**
 * The claim one unit of a run holds while it runs.
 *
 * Renewing slides that same lease forward; it does not grant a longer one. A
 * call that cannot renew while it waits has to return inside waitMs(), so the
 * checkpoint still finds the claim held. A unit asks cancelled() before every
 * paid call.
 */
final readonly class RunClaim
{
    /** Left after a call returns, so the unit can still checkpoint. */
    public const MARGIN_MS = 15_000;

    public function __construct(
        private RunCheckpointStore $store,
        public string $runId,
        public int $seq,
        public string $token,
        public int $leaseMs,
    ) {
        self::assertToken($token);
    }

    public static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function isToken(string $token): bool
    {
        return 1 === preg_match('/^[0-9a-f]{32}$/D', $token);
    }

    public static function assertToken(string $token): void
    {
        if (!self::isToken($token)) {
            throw new \InvalidArgumentException('A run claim needs a token.');
        }
    }

    /** False when this token no longer holds the unit. */
    public function renew(): bool
    {
        return $this->store->renew($this->runId, $this->seq, $this->token, $this->leaseMs);
    }

    /** How long a call may block before it renews again or returns. */
    public function waitMs(): int
    {
        return max(self::MARGIN_MS, $this->leaseMs - self::MARGIN_MS);
    }

    /** True once someone asked the run to stop. Checked before every paid call. */
    public function cancelled(): bool
    {
        return $this->store->cancelRequested($this->runId);
    }
}
