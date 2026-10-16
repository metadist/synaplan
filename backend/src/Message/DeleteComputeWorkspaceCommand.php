<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Delete the sidecar folder of a workspace whose account was deleted. The
 * row that pointed at the folder went with the account, so this message is
 * the only reference left: the transport retries a busy or unreachable
 * sidecar and parks the message in `failed` after the last attempt.
 *
 * Queued in: async_index
 * Handled by: DeleteComputeWorkspaceCommandHandler
 */
final readonly class DeleteComputeWorkspaceCommand
{
    public function __construct(
        public string $workspaceId,
    ) {
    }
}
