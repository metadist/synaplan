<?php

declare(strict_types=1);

namespace App\Service\Agent;

use App\Entity\Agent;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Service\Agent\Exception\AgentNotAccessibleException;
use App\Service\Iam\AccessGate;
use App\Service\Iam\Permission;
use App\Service\Iam\ResourceKind\AgentKind;

final readonly class AgentAccess
{
    public function __construct(
        private AgentRepository $agents,
        private AccessGate $accessGate,
    ) {
    }

    public function find(int $id): ?Agent
    {
        $agent = $this->agents->find($id);

        return $agent instanceof Agent ? $agent : null;
    }

    public function can(User $user, Agent $agent, Permission $level): bool
    {
        $id = $agent->getId();
        if (null === $id) {
            return false;
        }
        if ($agent->getOwnerId() === (int) $user->getId()) {
            return true;
        }

        return $this->accessGate->decide($user, AgentKind::KEY, (string) $id, $level);
    }

    public function require(User $user, int $id, Permission $level): Agent
    {
        $agent = $this->find($id);
        if (!$agent instanceof Agent || !$this->can($user, $agent, $level)) {
            throw AgentNotAccessibleException::forId($id);
        }

        return $agent;
    }

    public function highest(User $user, Agent $agent): ?Permission
    {
        $id = $agent->getId();
        if (null === $id) {
            return null;
        }
        if ($agent->getOwnerId() === (int) $user->getId()) {
            return Permission::Manage;
        }

        return $this->accessGate->highestGranted($user, AgentKind::KEY, (string) $id);
    }
}
