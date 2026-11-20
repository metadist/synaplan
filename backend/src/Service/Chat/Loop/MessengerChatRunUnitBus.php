<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Message\RunChatUnit;
use App\Service\Run\RunUnitBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/** Puts the next unit of a chat run on the worker queue. */
final readonly class MessengerChatRunUnitBus implements RunUnitBus
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function dispatch(string $runId, string $unit, int $seq, int $delayMs): void
    {
        $this->bus->dispatch(new RunChatUnit($runId, $unit, $seq), [new DelayStamp($delayMs)]);
    }
}
