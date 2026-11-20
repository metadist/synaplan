<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Service\Run\RunUnit;

/**
 * Claims one chat run unit, runs it, and queues the next. A duplicate
 * delivery finds the claim taken and returns before any paid call.
 */
final readonly class ChatRunWorker
{
    private RunUnit $unit;

    public function __construct(ChatRunStore $store, ChatRunUnitHandler $handler, MessengerChatRunUnitBus $bus)
    {
        $this->unit = new RunUnit($store->runs(), $handler, $bus);
    }

    public function handle(string $runId, string $unit, int $seq): void
    {
        $this->unit->handle($runId, $unit, $seq);
    }
}
