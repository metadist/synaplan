<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

/**
 * The account being erased, and the best-effort cleanups to run once its
 * rows are committed away.
 */
final class UserErasure
{
    /** @var list<\Closure(): void> */
    private array $afterCommit = [];

    public function __construct(
        public readonly int $userId,
        public readonly string $email,
    ) {
    }

    /** @param \Closure(): void $cleanup */
    public function afterCommit(\Closure $cleanup): void
    {
        $this->afterCommit[] = $cleanup;
    }

    /** @return list<\Closure(): void> in the order the erasers queued them */
    public function pendingCleanups(): array
    {
        return $this->afterCommit;
    }
}
