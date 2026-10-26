<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RunResearchUnit;
use App\Service\Research\Run\ResearchUnitWorker;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RunResearchUnitHandler
{
    public function __construct(private ResearchUnitWorker $worker)
    {
    }

    public function __invoke(RunResearchUnit $message): void
    {
        $this->worker->handle($message->runId(), $message->unit(), $message->seq());
    }
}
