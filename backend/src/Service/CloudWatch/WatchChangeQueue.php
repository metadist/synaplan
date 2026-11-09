<?php

declare(strict_types=1);

namespace App\Service\CloudWatch;

/**
 * Notices waiting until the request has committed. Each person on a cloud is
 * told once, not once per watch, and not while the identity row is still
 * locked. The cloud reads that person's watches with the person's own
 * credential; there is no cloud-wide index to refresh.
 */
final class WatchChangeQueue
{
    /** @var array<string, array{instanceId: string, externalId: string}> */
    private array $changes = [];

    public function add(string $instanceId, string $externalId): void
    {
        if ('' !== $instanceId && '' !== $externalId) {
            $this->changes[$instanceId."\0".$externalId] = ['instanceId' => $instanceId, 'externalId' => $externalId];
        }
    }

    /** @return list<array{instanceId: string, externalId: string}> */
    public function pull(): array
    {
        $changes = array_values($this->changes);
        $this->changes = [];

        return $changes;
    }
}
