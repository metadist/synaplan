<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\DeleteComputeWorkspaceCommand;
use App\Service\Compute\ComputeClient;
use App\Service\Compute\ComputeRefusedException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deletes one orphaned sidecar folder. A folder the sidecar no longer knows
 * is done; any other refusal (busy, unreachable) is thrown, so the transport
 * retries the message.
 */
#[AsMessageHandler]
final readonly class DeleteComputeWorkspaceCommandHandler
{
    private const ALREADY_GONE = 'workspace_not_found';

    public function __construct(
        private ComputeClient $client,
    ) {
    }

    public function __invoke(DeleteComputeWorkspaceCommand $command): void
    {
        try {
            $this->client->deleteWorkspace($command->workspaceId);
        } catch (ComputeRefusedException $e) {
            if (self::ALREADY_GONE !== $e->errorCode()) {
                throw $e;
            }
        }
    }
}
