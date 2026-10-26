<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Message\RunResearchUnit;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/** Puts the next research step on the queue after a short gap. */
final readonly class MessengerResearchUnitBus implements ResearchUnitBus
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function dispatch(string $runId, string $unit, int $seq, int $delayMs): void
    {
        $this->bus->dispatch(new RunResearchUnit($runId, $unit, $seq), [new DelayStamp($delayMs)]);
    }
}
