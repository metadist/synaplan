<?php

declare(strict_types=1);

namespace App\Service\CloudWatch;

/**
 * Notices waiting until the request has committed. One cloud is told once,
 * not once per watch, and not while the identity row is still locked.
 */
final class WatchChangeQueue
{
    /** @var array<string, true> */
    private array $instances = [];

    public function add(string $instanceId): void
    {
        if ('' !== $instanceId) {
            $this->instances[$instanceId] = true;
        }
    }

    /** @return list<string> */
    public function pull(): array
    {
        $ids = array_keys($this->instances);
        $this->instances = [];

        return $ids;
    }
}
