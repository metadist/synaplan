<?php

declare(strict_types=1);

namespace App\Service\Chat;

use App\AI\Credential\ChatReadinessService;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\FileRepository;
use App\Service\Agent\AgentConfig;

/**
 * The four getting-started ticks, in one place.
 *
 * A missing key means "do not show this step": the check failed, or the step
 * does not apply (assistants switched off, or the person is not an admin).
 * A false value means the step is still to do.
 */
final readonly class GettingStartedStatusService
{
    public function __construct(
        private FileRepository $files,
        private AgentRepository $agents,
        private AgentConfig $agentConfig,
        private ChatReadinessService $chatReadiness,
        private ConnectedAppProbe $apps,
    ) {
    }

    /**
     * @return array<string, bool>
     */
    public function items(User $user): array
    {
        $userId = (int) $user->getId();
        $items = [];

        try {
            $items['file'] = $this->files->existsForUser($userId);
        } catch (\Throwable) {
            // Leave the step out. An error is not "no files".
        }

        try {
            if ($this->agentConfig->isEnabled($userId)) {
                $items['assistant'] = $this->agents->existsForOwner($userId);
            }
        } catch (\Throwable) {
            // Feature probe or the query failed: hide the step.
        }

        try {
            $items['app'] = $this->apps->isAnyConnected($user);
        } catch (\Throwable) {
            // Hide the step rather than mark every app as not connected.
        }

        if ($user->isAdmin()) {
            try {
                $items['provider'] = $this->chatReadiness->isChatReady(userId: $userId);
            } catch (\Throwable) {
                // Hide the step. The setup page stays reachable from the menu.
            }
        }

        return $items;
    }
}
