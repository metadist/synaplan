<?php

declare(strict_types=1);

namespace App\Service\Agent;

use App\Entity\Agent;
use App\Entity\AgentVersion;
use App\Entity\Prompt;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\AgentVersionRepository;
use App\Repository\PromptRepository;
use App\Service\Agent\Definition\AgentDefinitionValidator;
use App\Service\Agent\Exception\AgentNothingChangedException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AgentPublisher
{
    public function __construct(
        private AgentDefinitionValidator $validator,
        private AgentVersionRepository $versions,
        private AgentRepository $agents,
        private PromptRepository $prompts,
        private EntityManagerInterface $em,
    ) {
    }

    public function publish(Agent $agent, User $actor, string $changelog): AgentVersion
    {
        $agentId = $agent->getId();
        if (null === $agentId) {
            throw new \InvalidArgumentException('Assistant must be persisted before publish');
        }

        $definition = $this->validator->validate($agent->getDraft());
        $promptText = $this->instructionText($agent->getPromptId());
        $payload = $definition->toArray();

        $latest = $this->versions->findLatest($agentId);
        if (null !== $latest && $this->identical($latest, $payload, $promptText)) {
            throw AgentNothingChangedException::identical();
        }

        $version = new AgentVersion(
            $agentId,
            $this->versions->nextVersionNumber($agentId),
            $payload,
            $promptText,
            (int) $actor->getId(),
            '' === trim($changelog) ? null : trim($changelog),
        );

        $this->em->wrapInTransaction(function () use ($agent, $version): void {
            $this->versions->save($version);
            $versionId = $version->getId();
            if (null === $versionId) {
                throw new \RuntimeException('Failed to persist assistant version');
            }
            $agent->setPublishedVersionId($versionId);
            $agent->setStatus(Agent::STATUS_PUBLISHED);
            $this->agents->save($agent);
        });

        return $version;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function identical(AgentVersion $latest, array $payload, string $promptText): bool
    {
        return $latest->getPromptText() === $promptText
            && $latest->getDefinition() === $payload;
    }

    private function instructionText(int $promptId): string
    {
        $prompt = $this->prompts->find($promptId);
        if ($prompt instanceof Prompt && '' !== trim($prompt->getPrompt())) {
            return $prompt->getPrompt();
        }

        return AgentService::DEFAULT_INSTRUCTION;
    }
}
