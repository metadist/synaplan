<?php

declare(strict_types=1);

namespace App\Service\Agent;

use App\Entity\Agent;
use App\Entity\Prompt;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\AgentVersionRepository;
use App\Repository\PromptRepository;
use App\Service\Agent\Definition\AgentDefinition;
use App\Service\Agent\Definition\AgentDefinitionValidator;
use App\Service\Agent\Exception\AgentNotAccessibleException;
use App\Service\Agent\Exception\AgentNotDraftException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AgentService
{
    public const DEFAULT_INSTRUCTION = 'You are a helpful AI assistant. Follow the user\'s instructions carefully and answer in the language they write in.';

    public function __construct(
        private AgentRepository $agents,
        private AgentVersionRepository $versions,
        private PromptRepository $prompts,
        private AgentDefinitionValidator $validator,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<Agent>
     */
    public function listOwned(int $ownerId): array
    {
        return $this->agents->findByOwner($ownerId);
    }

    public function getOwned(int $id, int $ownerId): ?Agent
    {
        return $this->agents->findByIdAndOwner($id, $ownerId);
    }

    /**
     * @param array<string, mixed>|null $draft
     */
    public function create(
        User $owner,
        string $name,
        ?int $promptId = null,
        ?array $draft = null,
        ?string $description = null,
        ?string $icon = null,
    ): Agent {
        $ownerId = (int) $owner->getId();
        $name = trim($name);
        if ('' === $name) {
            throw new \InvalidArgumentException('name is required');
        }
        if (strlen($name) > 128) {
            throw new \InvalidArgumentException('name must be at most 128 characters');
        }

        $definition = null === $draft
            ? AgentDefinition::defaults()
            : $this->validator->validate($draft);

        $slug = $this->uniqueSlug($ownerId, AgentSlugger::from($name));
        $resolvedPromptId = null !== $promptId
            ? $this->assertOwnedPrompt($promptId, $ownerId)
            : $this->createInstructionPrompt($ownerId, $slug, $name);

        $agent = new Agent($ownerId, $resolvedPromptId, $slug, $name, $definition->toArray());
        if (null !== $description) {
            $agent->setDescription('' === trim($description) ? null : trim($description));
        }
        if (null !== $icon) {
            $this->assertIcon($icon);
            $agent->setIcon($icon);
        }

        $this->agents->save($agent);

        return $agent;
    }

    /**
     * @param array<string, mixed> $patch
     */
    public function update(Agent $agent, array $patch): Agent
    {
        if (isset($patch['name']) && is_string($patch['name'])) {
            $name = trim($patch['name']);
            if ('' === $name) {
                throw new \InvalidArgumentException('name must not be empty');
            }
            if (strlen($name) > 128) {
                throw new \InvalidArgumentException('name must be at most 128 characters');
            }
            $agent->setName($name);
        }
        if (array_key_exists('description', $patch)) {
            $description = $patch['description'];
            if (null !== $description && !is_string($description)) {
                throw new \InvalidArgumentException('description must be a string or null');
            }
            $agent->setDescription(null === $description || '' === trim($description) ? null : trim($description));
        }
        if (array_key_exists('icon', $patch)) {
            if (!is_string($patch['icon'])) {
                throw new \InvalidArgumentException('icon must be a string');
            }
            $this->assertIcon($patch['icon']);
            $agent->setIcon($patch['icon']);
        }
        if (array_key_exists('draft', $patch)) {
            if (!is_array($patch['draft'])) {
                throw new \InvalidArgumentException('draft must be an object');
            }
            /** @var array<mixed> $draft */
            $draft = $patch['draft'];
            $agent->setDraft($this->validator->validate($draft)->toArray());
        }
        if (array_key_exists('routable', $patch)) {
            $agent->setRoutable((bool) $patch['routable']);
        }

        $this->agents->save($agent);

        return $agent;
    }

    public function delete(Agent $agent): void
    {
        if (!$agent->isDraft()) {
            throw AgentNotDraftException::cannotDelete($agent->getStatus());
        }
        $id = $agent->getId();
        if (null !== $id) {
            $this->versions->deleteForAgent($id);
        }
        $this->agents->remove($agent);
    }

    public function requireOwned(int $id, int $ownerId): Agent
    {
        $agent = $this->getOwned($id, $ownerId);
        if (!$agent instanceof Agent) {
            throw AgentNotAccessibleException::forId($id);
        }

        return $agent;
    }

    /**
     * Copy an owned assistant into a new draft. Files are never copied;
     * the clone gets its own `TASKPROMPT:agent:{slug}` folder.
     */
    public function clone(User $owner, int $sourceId): Agent
    {
        $ownerId = (int) $owner->getId();
        $source = $this->requireOwned($sourceId, $ownerId);
        $sourceIdResolved = $source->getId();
        if (null === $sourceIdResolved) {
            throw AgentNotAccessibleException::forId($sourceId);
        }

        $draft = $this->prepareCloneDraft($source->getDraft());
        $definition = $this->validator->validate($draft);
        $slug = $this->uniqueSlug($ownerId, $this->copySlugBase($source->getSlug()));
        $name = $this->cloneName($source->getName());
        $promptText = $this->instructionText($source->getPromptId());
        $promptId = $this->createInstructionPrompt($ownerId, $slug, $name, $promptText);

        $agent = new Agent($ownerId, $promptId, $slug, $name, $definition->toArray());
        $agent->setParentId($sourceIdResolved);
        $agent->setSource(Agent::SOURCE_MANUAL);
        if (null !== $source->getDescription()) {
            $agent->setDescription($source->getDescription());
        }
        if ('' !== $source->getIcon()) {
            $agent->setIcon($source->getIcon());
        }

        $this->agents->save($agent);

        return $agent;
    }

    private function uniqueSlug(int $ownerId, string $base): string
    {
        $slug = $base;
        $n = 2;
        while ($this->agents->slugTaken($ownerId, $slug)) {
            $suffix = '-'.$n;
            $trimmed = substr($base, 0, AgentSlugger::MAX_LENGTH - strlen($suffix));
            $slug = rtrim($trimmed, '-').$suffix;
            ++$n;
        }

        return $slug;
    }

    private function assertOwnedPrompt(int $promptId, int $ownerId): int
    {
        $prompt = $this->prompts->find($promptId);
        if (!$prompt instanceof Prompt || $prompt->getOwnerId() !== $ownerId) {
            throw new \InvalidArgumentException('promptId was not found');
        }

        return $promptId;
    }

    private function createInstructionPrompt(int $ownerId, string $slug, string $name, ?string $text = null): int
    {
        $prompt = new Prompt();
        $prompt->setOwnerId($ownerId);
        $prompt->setLanguage('en');
        $prompt->setTopic('agent:'.$slug);
        $prompt->setShortDescription($name);
        $prompt->setPrompt(null !== $text && '' !== trim($text) ? $text : self::DEFAULT_INSTRUCTION);
        $this->em->persist($prompt);
        $this->em->flush();

        $id = $prompt->getId();
        if (null === $id) {
            throw new \RuntimeException('Failed to create the assistant instruction prompt');
        }

        return $id;
    }

    private function assertIcon(string $icon): void
    {
        if (strlen($icon) > 64) {
            throw new \InvalidArgumentException('icon must be at most 64 characters');
        }
    }

    /**
     * @param array<string, mixed> $draft
     *
     * @return array<string, mixed>
     */
    private function prepareCloneDraft(array $draft): array
    {
        $knowledge = $draft['knowledge'] ?? [];
        if (!is_array($knowledge)) {
            $knowledge = [];
        }
        $knowledge['ownFolder'] = true;
        $draft['knowledge'] = $knowledge;

        return $draft;
    }

    private function copySlugBase(string $sourceSlug): string
    {
        $suffix = '-copy';
        $maxBase = AgentSlugger::MAX_LENGTH - strlen($suffix);
        $trimmed = rtrim(substr($sourceSlug, 0, $maxBase), '-');

        return $trimmed.$suffix;
    }

    private function cloneName(string $name): string
    {
        $copy = $name.' copy';

        return strlen($copy) > 128 ? $name : $copy;
    }

    private function instructionText(int $promptId): string
    {
        $prompt = $this->prompts->find($promptId);
        if ($prompt instanceof Prompt && '' !== trim($prompt->getPrompt())) {
            return $prompt->getPrompt();
        }

        return self::DEFAULT_INSTRUCTION;
    }
}
