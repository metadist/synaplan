<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RunChatUnit;
use App\Service\Chat\Loop\ChatRunWorker;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A failed model call does not reach this handler: the unit checkpoints it
 * and the run tries once more or ends with the reason. What does throw here
 * is a lost unit; its claim stays until the lease runs out and the reaper
 * queues it again.
 */
#[AsMessageHandler]
final readonly class RunChatUnitHandler
{
    public function __construct(private ChatRunWorker $worker)
    {
    }

    public function __invoke(RunChatUnit $message): void
    {
        $this->worker->handle($message->runId(), $message->unit(), $message->seq());
    }
}
