<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ResumeSavedTaskRunCommand;
use App\Service\SavedTask\SavedTaskResumeService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ResumeSavedTaskRunCommandHandler
{
    public function __construct(
        private SavedTaskResumeService $resume,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ResumeSavedTaskRunCommand $command): void
    {
        try {
            $this->resume->resume($command->runId, $command->nodeId, $command->approvalId);
        } catch (\Throwable $e) {
            $this->logger->warning('ResumeSavedTaskRun failed', [
                'run_id' => $command->runId,
                'node_id' => $command->nodeId,
                'approval_id' => $command->approvalId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
