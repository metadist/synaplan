<?php

declare(strict_types=1);

namespace App\Service\Usage;

use App\Entity\User;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched by {@see \App\Service\RateLimitService::recordUsage()} right after
 * the BUSELOG row is written. `$cost` is the raw provider cost in USD
 * (BUSELOG.BCOST, no markup); `$metadata` is exactly what was stored in
 * BMETADATA, so it never contains prompt or answer text.
 *
 * Listeners run synchronously inside the request that spent the money. A
 * listener that throws is logged and ignored; the usage row stays written.
 * During a streamed answer the request stack is already empty, so a listener
 * cannot rely on RequestStack::getCurrentRequest().
 */
final class UsageRecorded extends Event
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly User $user,
        public readonly string $action,
        public readonly float $cost,
        public readonly array $metadata = [],
    ) {
    }
}
