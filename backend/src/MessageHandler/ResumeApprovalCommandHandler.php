<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Approval;
use App\Message\ResumeApprovalCommand;
use App\Message\ResumeSavedTaskRunCommand;
use App\Repository\ApprovalRepository;
use App\Repository\CustomToolRepository;
use App\Repository\UserRepository;
use App\Service\Tool\ApprovalReference;
use App\Service\Tool\Custom\HttpToolExecutor;
use App\Service\Tool\Exception\ToolNotRegisteredException;
use App\Service\Tool\ToolRegistry;
use App\Service\Tool\ToolSource;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class ResumeApprovalCommandHandler
{
    public function __construct(
        private ApprovalRepository $approvals,
        private ToolRegistry $registry,
        private UserRepository $users,
        private CustomToolRepository $customTools,
        private HttpToolExecutor $httpExecutor,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ResumeApprovalCommand $command): void
    {
        $approval = $this->approvals->find($command->approvalId);
        if (!$approval instanceof Approval || Approval::STATUS_APPROVED !== $approval->getStatus()) {
            return;
        }

        $reference = ApprovalReference::parse($approval->getRequestedBy());
        if (ApprovalReference::KIND_TASK_RUN === $reference->kind && null !== $reference->runId && null !== $reference->nodeId) {
            $this->bus->dispatch(new ResumeSavedTaskRunCommand($reference->runId, $reference->nodeId, (int) $approval->getId()));

            return;
        }

        $user = $this->users->find($approval->getOwnerId());
        if (null === $user) {
            $approval->markFailed('owner_missing');
            $this->approvals->save($approval);

            return;
        }

        $descriptor = $this->registry->get($approval->getOwnerId(), $approval->getTool());
        if (null === $descriptor) {
            $approval->markFailed('tool_not_registered');
            $this->approvals->save($approval);
            $this->logger->warning('ResumeApproval: tool left the registry', ['tool' => $approval->getTool()]);

            throw new ToolNotRegisteredException($approval->getTool());
        }

        if (ToolSource::Custom === $descriptor->source) {
            $toolId = $descriptor->meta['toolId'] ?? null;
            $tool = is_numeric($toolId) ? $this->customTools->find((int) $toolId) : null;
            if (null === $tool) {
                $approval->markFailed('tool_not_registered');
                $this->approvals->save($approval);

                throw new ToolNotRegisteredException($approval->getTool());
            }
            $result = $this->httpExecutor->execute($tool, $approval->getArgs() ?? [], $approval->getOwnerId());
            $approval->markExecuted('custom:'.$tool->getId().':'.$result['status']);
            $this->approvals->save($approval);

            return;
        }

        $approval->markExecuted('chat');
        $this->approvals->save($approval);
    }
}
