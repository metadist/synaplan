<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ResumeMediaPlanCommand;
use App\Service\Media\MediaDependentPlanContinuation;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
final readonly class ResumeMediaPlanCommandHandler
{
    private const RETRY_DELAY_MS = 2000;

    public function __construct(
        private MediaDependentPlanContinuation $continuation,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ResumeMediaPlanCommand $command): void
    {
        try {
            if (!$this->continuation->continue($command->messageId, $command->nodeId, $command->jobKey)) {
                $this->logger->info('ResumeMediaPlan waits for the plan lock', [
                    'message_id' => $command->messageId,
                    'node_id' => $command->nodeId,
                    'job_key' => $command->jobKey,
                ]);
                $this->bus->dispatch($command, [new DelayStamp(self::RETRY_DELAY_MS)]);

                return;
            }
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
