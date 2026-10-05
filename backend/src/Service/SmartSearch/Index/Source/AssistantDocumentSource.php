<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Index\Source;

use App\Entity\Agent;
use App\Repository\AgentRepository;
use App\Service\Agent\AgentConfig;
use App\Service\SmartSearch\Index\ResolvedItem;
use App\Service\SmartSearch\Index\SearchDocument;
use App\Service\SmartSearch\Index\SearchDocumentSourceInterface;

/**
 * The user's own AI assistants (name + description). Archived ones are not
 * offered; the kind disappears while the assistants feature is off.
 */
final readonly class AssistantDocumentSource implements SearchDocumentSourceInterface
{
    public const KIND = 'assistant';
    private const TRACKED_FIELDS = ['name', 'description', 'status'];

    public function __construct(
        private AgentRepository $agents,
        private AgentConfig $agentConfig,
    ) {
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function isEnabledFor(int $userId): bool
    {
        return $this->agentConfig->isEnabled($userId);
    }

    public function refFor(object $entity, array $changeSet): ?array
    {
        if (!$entity instanceof Agent || $entity->getOwnerId() <= 0) {
            return null;
        }
        if ([] !== $changeSet && [] === array_intersect(self::TRACKED_FIELDS, array_keys($changeSet))) {
            return null;
        }
        $id = $entity->getId();

        return null === $id ? null : ['userId' => $entity->getOwnerId(), 'refId' => (string) $id];
    }

    public function build(int $userId, string $refId): ?SearchDocument
    {
        $agent = $this->agents->find((int) $refId);
        if (!$agent instanceof Agent || $agent->getOwnerId() !== $userId) {
            return null;
        }

        return $this->document($agent);
    }

    public function allForUser(int $userId): iterable
    {
        foreach ($this->agents->findBy(['ownerId' => $userId]) as $agent) {
            $document = $this->document($agent);
            if (null !== $document) {
                yield $document;
            }
        }
    }

    public function sharedRefIds(int $userId): array
    {
        return [];
    }

    public function resolve(int $userId, array $refIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $refIds), static fn (int $id): bool => $id > 0));
        if ([] === $ids) {
            return [];
        }

        $resolved = [];
        foreach ($this->agents->findBy(['ownerId' => $userId, 'id' => $ids]) as $agent) {
            if (Agent::STATUS_ARCHIVED === $agent->getStatus()) {
                continue;
            }
            $id = (string) $agent->getId();
            $resolved[$id] = new ResolvedItem(title: $agent->getName(), route: '/ai/assistants/'.$id);
        }

        return $resolved;
    }

    private function document(Agent $agent): ?SearchDocument
    {
        $id = $agent->getId();
        if (null === $id || Agent::STATUS_ARCHIVED === $agent->getStatus()) {
            return null;
        }

        return new SearchDocument(
            userId: $agent->getOwnerId(),
            kind: self::KIND,
            refId: (string) $id,
            title: $agent->getName(),
            body: (string) $agent->getDescription(),
            updated: $agent->getUpdated(),
        );
    }
}
