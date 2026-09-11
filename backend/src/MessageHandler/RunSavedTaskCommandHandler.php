<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RunSavedTaskCommand;
use App\Service\SavedTask\SavedTaskDisabledException;
use App\Service\SavedTask\SavedTaskNotFoundException;
use App\Service\SavedTask\SavedTaskRunner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RunSavedTaskCommandHandler
{
    public function __construct(
        private SavedTaskRunner $runner,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RunSavedTaskCommand $command): void
    {
        try {
            $this->runner->run($command->ownerId, $command->taskId, '', $command->trigger, $command->triggerPayload);
        } catch (SavedTaskDisabledException|SavedTaskNotFoundException $e) {
            // The task was turned off or removed between accept and run — nothing to retry.
            $this->logger->info('RunSavedTask skipped', [
                'task_id' => $command->taskId,
                'trigger' => $command->trigger,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
