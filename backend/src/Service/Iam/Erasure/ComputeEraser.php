<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\ComputeRun;
use App\Entity\ComputeWorkspace;
use App\Message\DeleteComputeWorkspaceCommand;
use App\Repository\ComputeRunRepository;
use App\Repository\ComputeWorkspaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The user's file-work runs and workspaces. The rows go inside the
 * transaction. Nothing finds a sidecar folder once its row is gone, so after
 * the commit each live folder is handed to a durable
 * {@see DeleteComputeWorkspaceCommand}; a busy sidecar never fails the
 * account deletion.
 */
#[AsTaggedItem(priority: 115)]
final readonly class ComputeEraser implements UserDataEraserInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private ComputeRunRepository $runs,
        private ComputeWorkspaceRepository $workspaces,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        $this->runs->deleteByUser($erasure->userId);

        foreach ($this->workspaces->findBy(['userId' => $erasure->userId]) as $workspace) {
            if (ComputeWorkspace::STATUS_DELETED !== $workspace->getStatus()) {
                $workspaceId = $workspace->getWorkspaceId();
                $erasure->afterCommit(fn () => $this->queueFolderDeletion($workspaceId));
            }
            $this->em->remove($workspace);
        }
    }

    public static function covers(): array
    {
        return [ComputeRun::class, ComputeWorkspace::class];
    }

    private function queueFolderDeletion(string $workspaceId): void
    {
        try {
            $this->bus->dispatch(new DeleteComputeWorkspaceCommand($workspaceId));
        } catch (\Throwable $e) {
            $this->logger->error('The sidecar folder of a deleted account could not be queued for deletion', [
                'workspace_id' => $workspaceId,
                'exception' => $e,
            ]);
        }
    }
}
