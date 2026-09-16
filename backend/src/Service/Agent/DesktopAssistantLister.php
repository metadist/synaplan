<?php

declare(strict_types=1);

namespace App\Service\Agent;

use App\Entity\Agent;
use App\Entity\User;
use App\Service\Iam\Permission;
use App\Service\Iam\ResourceKind\AgentKind;
use App\Service\Iam\ShareService;

/**
 * Assistants a paired key may run — owner drafts plus published shares.
 * Never returns the draft JSON.
 */
final readonly class DesktopAssistantLister
{
    public function __construct(
        private AgentService $agents,
        private AgentAccess $access,
        private AgentSerializer $serializer,
        private ShareService $shareService,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRunnable(User $user): array
    {
        $userId = (int) $user->getId();
        $out = [];

        foreach ($this->agents->listOwned($userId) as $agent) {
            if ($agent->isArchived()) {
                continue;
            }
            $out[] = $this->view($agent, true);
        }

        foreach ($this->shareService->listSharedWith($userId, AgentKind::KEY) as $row) {
            $permission = Permission::tryFrom((string) $row['permission']);
            if (!$permission instanceof Permission || !$permission->implies(Permission::Use)) {
                continue;
            }
            $id = (int) $row['card']->id;
            $agent = $this->access->find($id);
            if (!$agent instanceof Agent || $agent->isArchived() || $agent->getOwnerId() === $userId) {
                continue;
            }
            if (!$agent->hasPublishedVersion()) {
                continue;
            }
            $out[] = $this->view($agent, false);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function oneRunnable(User $user, int $id): ?array
    {
        try {
            $agent = $this->access->require($user, $id, Permission::Use);
        } catch (\Throwable) {
            return null;
        }
        if ($agent->isArchived()) {
            return null;
        }

        $owner = $agent->getOwnerId() === (int) $user->getId();

        return $this->view($agent, $owner);
    }

    /**
     * @return array<string, mixed>
     */
    private function view(Agent $agent, bool $owner): array
    {
        $published = $this->agents->publishedVersion($agent);
        $definition = null;
        if (null !== $published) {
            $definition = $published->getDefinition();
        } elseif ($owner) {
            $definition = $agent->getDraft();
        }

        return $this->serializer->publicView($agent, is_array($definition) ? $definition : []);
    }
}
