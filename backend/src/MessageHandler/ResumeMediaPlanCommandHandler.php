<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ResumeMediaPlanCommand;
use App\Service\Media\MediaDependentPlanContinuation;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ResumeMediaPlanCommandHandler
{
    public function __construct(
        private MediaDependentPlanContinuation $continuation,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ResumeMediaPlanCommand $command): void
    {
        try {
            $this->continuation->continue($command->messageId, $command->nodeId, $command->jobKey);
        } catch (\Throwable $e) {
            $this->logger->warning('ResumeMediaPlan failed', [
                'message_id' => $command->messageId,
                'node_id' => $command->nodeId,
                'job_key' => $command->jobKey,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
