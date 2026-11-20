<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Service\Run\RunReaper;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Recovers chat runs whose worker disappeared and moves on runs that wait,
 * from `app:runs:reap`.
 */
#[AutoconfigureTag('app.run_reaper')]
final readonly class ChatRunReaper
{
    private RunReaper $reaper;

    public function __construct(
        ChatRunStore $store,
        ChatRunReapPolicy $policy,
        MessengerChatRunUnitBus $bus,
        ClockInterface $clock,
        LoggerInterface $logger,
    ) {
        $this->reaper = new RunReaper($store->runs(), $policy, $bus, $clock, $logger, ChatRunUnitHandler::LEASE_MS);
    }

    /**
     * @return array{redispatched: int, interrupted: int, waited: int}
     */
    public function reap(): array
    {
        return $this->reaper->reap();
    }
}
