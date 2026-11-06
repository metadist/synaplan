<?php

declare(strict_types=1);

namespace Plugin\Synafastbill\Command\Session;

/**
 * In-memory session store used by tests and the offline example. Honours the
 * `expires_at` timestamp so TTL behaviour can be exercised deterministically.
 */
final class InMemorySessionStore implements SessionStoreInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $sessions = [];

    public function __construct(
        private readonly int $now = 0,
    ) {
    }

    public function load(string $key): ?array
    {
        $state = $this->sessions[$key] ?? null;
        if (null === $state) {
            return null;
        }
        $expiresAt = (int) ($state['expires_at'] ?? 0);
        if ($expiresAt > 0 && $this->currentTime() > $expiresAt) {
            unset($this->sessions[$key]);

            return null;
        }

        return $state;
    }

    public function save(string $key, array $state): void
    {
        $this->sessions[$key] = $state;
    }

    public function clear(string $key): void
    {
        unset($this->sessions[$key]);
    }

    private function currentTime(): int
    {
        return $this->now > 0 ? $this->now : time();
    }
}
