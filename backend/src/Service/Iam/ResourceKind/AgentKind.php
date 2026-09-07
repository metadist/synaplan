<?php

declare(strict_types=1);

namespace App\Service\Iam\ResourceKind;

use App\Entity\Agent;
use App\Entity\AgentVersion;
use App\Repository\AgentRepository;
use App\Repository\AgentVersionRepository;
use App\Service\Agent\AgentConfig;
use App\Service\Iam\Exception\ShareNotAllowedException;
use App\Service\Iam\Permission;

/**
 * Published BAGENTS rows. The existing {@see AssistantKind} key stays bound
 * to BPROMPTS so instruction sharing is not collided with assistant ids.
 */
final readonly class AgentKind implements ShareableResourceKindInterface
{
    public const KEY = 'agent';

    public function __construct(
        private AgentRepository $agents,
        private AgentVersionRepository $versions,
        private AgentConfig $agentConfig,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function ownerId(string $resourceId): ?int
    {
        return $this->findAgent($resourceId)?->getOwnerId();
    }

    public function describe(string $resourceId): ResourceCard
    {
        $agent = $this->findAgent($resourceId);
        if (null === $agent) {
            return new ResourceCard($resourceId, $resourceId, 'assistant');
        }

        $version = $this->publishedVersion($agent);

        return new ResourceCard(
            (string) $agent->getId(),
            $agent->getName(),
            '' !== $agent->getIcon() ? $agent->getIcon() : 'assistant',
            [
                'ownerId' => $agent->getOwnerId(),
                'version' => $version?->getVersion(),
                'status' => $agent->getStatus(),
            ],
        );
    }

    public function listOwnedBy(int $userId): iterable
    {
        if (!$this->agentConfig->isEnabled($userId)) {
            return;
        }

        foreach ($this->agents->findPublishedByOwner($userId) as $agent) {
            $id = $agent->getId();
            if (null === $id) {
                continue;
            }
            yield $this->describe((string) $id);
        }
    }

    public function onShareChanged(string $resourceId): void
    {
    }

    public function supportedPermissions(): array
    {
        return [Permission::Read, Permission::Use, Permission::Edit];
    }

    public function assertShareable(string $resourceId): void
    {
        $agent = $this->findAgent($resourceId);
        if (null === $agent || !$agent->hasPublishedVersion() || $agent->isArchived()) {
            throw new ShareNotAllowedException('A draft or archived assistant cannot be shared.');
        }
    }

    private function findAgent(string $resourceId): ?Agent
    {
        if ('' === $resourceId || !ctype_digit($resourceId)) {
            return null;
        }
        $agent = $this->agents->find((int) $resourceId);

        return $agent instanceof Agent ? $agent : null;
    }

    private function publishedVersion(Agent $agent): ?AgentVersion
    {
        $id = $agent->getPublishedVersionId();
        if (null === $id) {
            return null;
        }
        $version = $this->versions->find($id);

        return $version instanceof AgentVersion ? $version : null;
    }
}
