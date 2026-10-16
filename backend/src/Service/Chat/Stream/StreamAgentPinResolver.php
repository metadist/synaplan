<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

use App\Entity\User;
use App\Service\Agent\AgentConfig;
use App\Service\Agent\AgentRuntimeResolver;
use App\Service\Agent\AgentService;
use App\Service\Agent\Exception\AgentArchivedException;
use App\Service\Agent\Exception\AgentNotAccessibleException;
use App\Service\Agent\Exception\AgentNotPublishedException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The assistant a streamed turn is pinned to (`agentId`, optionally `draft`).
 *
 * The pin counts only while assistants are enabled for the person. A draft
 * is the owner's test panel; anyone else gets 403. An assistant the person
 * cannot reach, or one without a published version, is 404; an archived
 * one is 410.
 */
final readonly class StreamAgentPinResolver
{
    public function __construct(
        private AgentConfig $agentConfig,
        private AgentService $agentService,
        private AgentRuntimeResolver $agentRuntimeResolver,
    ) {
    }

    /**
     * @throws StreamRequestRejected
     */
    public function resolve(User $user, ?int $agentId, bool $draft, ?int $chatId): ?StreamAgentPin
    {
        $pinnedAgentId = $this->pinnedAgentId($user, $agentId);
        if ($draft) {
            $this->assertOwnsDraft($user, $pinnedAgentId);
        }

        $versionId = $this->runtimeVersionId($user, $pinnedAgentId, $draft, $chatId);

        return null === $pinnedAgentId ? null : new StreamAgentPin($pinnedAgentId, $draft, $versionId);
    }

    private function pinnedAgentId(User $user, ?int $agentId): ?int
    {
        if (null === $agentId || $agentId < 1) {
            return null;
        }
        if (!$this->agentConfig->isEnabled((int) $user->getId())) {
            return null;
        }

        return $agentId;
    }

    private function assertOwnsDraft(User $user, ?int $agentId): void
    {
        if (null === $agentId || $agentId < 1) {
            throw StreamRequestRejected::json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->agentService->requireOwned($agentId, (int) $user->getId());
        } catch (AgentNotAccessibleException) {
            throw StreamRequestRejected::json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }
    }

    private function runtimeVersionId(User $user, ?int $agentId, bool $draft, ?int $chatId): ?int
    {
        if (null === $agentId || $agentId < 1) {
            return null;
        }

        try {
            return $this->agentRuntimeResolver->resolve($agentId, $user, $draft, $chatId)->agentVersionId;
        } catch (AgentNotAccessibleException|AgentNotPublishedException) {
            throw StreamRequestRejected::json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        } catch (AgentArchivedException) {
            throw StreamRequestRejected::json(['error' => 'archived'], Response::HTTP_GONE);
        }
    }
}
