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
use App\Repository\UserRepository;
use App\Service\Agent\Definition\AgentDefinition;
use App\Service\Agent\Definition\AgentDefinitionValidator;
use App\Service\Agent\Exception\AgentNotAccessibleException;
use App\Service\Agent\Exception\AgentNotDraftException;
use App\Service\Iam\Permission;
use App\Service\Iam\ResourceKind\AgentKind;
use App\Service\Iam\ShareService;
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
        private AgentAccess $access,
        private ShareService $shareService,
        private AgentCascadeCleanup $cascade,
        private UserRepository $users,
        private AgentSerializer $serializer,
        private ?AgentTriggerMaterializer $materializer = null,
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
        if (isset($patch['status']) && is_string($patch['status'])) {
            $this->applyStatus($agent, $patch['status']);
        }

        $this->agents->save($agent);

        return $agent;
    }

    public function archive(Agent $agent): Agent
    {
        $this->applyStatus($agent, Agent::STATUS_ARCHIVED);
        $this->agents->save($agent);
        $this->materializer?->disable($agent);

        return $agent;
    }

    public function unarchive(Agent $agent): Agent
    {
        $this->applyStatus($agent, Agent::STATUS_PUBLISHED);
        $this->agents->save($agent);

        return $agent;
    }

    public function delete(Agent $agent): void
    {
        if ($agent->isPublished()) {
            throw AgentNotDraftException::cannotDelete($agent->getStatus());
        }
        $external = $this->cascade->unshareAndRemoveDependents($agent);
        $this->agents->remove($agent);
        $this->cascade->purgeExternal($external);
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
     * Clone a readable assistant. Published snapshots are copied; an owner
     * may still clone an unpublished draft. Files are never copied.
     *
     * @return list<array<string, mixed>>
     */
    public function galleryCards(User $user): array
    {
        $userId = (int) $user->getId();
        $ownerName = $this->serializer->displayName($user);
        $cards = [];
        foreach ($this->listOwned($userId) as $agent) {
            $cards[] = $this->serializer->galleryCard(
                $agent,
                $agent->getDraft(),
                $ownerName,
                'mine',
                $this->publishedVersionNumber($agent),
                null,
                Permission::Manage->value,
            );
        }

        foreach ($this->shareService->listSharedWith($userId, AgentKind::KEY) as $row) {
            $id = (int) $row['card']->id;
            $agent = $this->agents->find($id);
            if (!$agent instanceof Agent || $agent->isArchived() || $agent->getOwnerId() === $userId) {
                continue;
            }
            // Recipients see the published snapshot only; the draft is the
            // owner's work in progress even for editors (they open it in the builder).
            $published = $this->publishedVersion($agent);
            if (null === $published) {
                continue;
            }
            $cards[] = $this->serializer->galleryCard(
                $agent,
                $published->getDefinition(),
                $this->ownerDisplayName($agent->getOwnerId()),
                'shared',
                $published->getVersion(),
                $this->shareService->sharedViaFor($userId, AgentKind::KEY, (string) $id),
                $row['permission'],
            );
        }

        return $cards;
    }

    public function clone(User $owner, int $sourceId): Agent
    {
        $source = $this->access->require($owner, $sourceId, Permission::Read);
        $sourceIdResolved = $source->getId();
        if (null === $sourceIdResolved) {
            throw AgentNotAccessibleException::forId($sourceId);
        }

        $ownerId = (int) $owner->getId();
        $isOwner = $source->getOwnerId() === $ownerId;
        $published = $this->publishedVersion($source);
        if (null !== $published) {
            $sourceDraft = $published->getDefinition();
            $promptText = $published->getPromptText();
        } elseif ($isOwner) {
            $sourceDraft = $source->getDraft();
            $promptText = $this->instructionText($source->getPromptId());
        } else {
            throw AgentNotAccessibleException::forId($sourceId);
        }

        $draft = $this->prepareCloneDraft($sourceDraft);
        $definition = $this->validator->validate($draft);
        $slug = $this->uniqueSlug($ownerId, $this->copySlugBase($source->getSlug()));
        $name = $this->cloneName($source->getName());
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

    /**
     * @param array<string, mixed> $draft
     */
    public function importDraft(
        User $owner,
        string $name,
        string $preferredSlug,
        array $draft,
        ?string $instruction = null,
        ?string $description = null,
        ?string $icon = null,
        string $source = Agent::SOURCE_IMPORT,
    ): Agent {
        $ownerId = (int) $owner->getId();
        $definition = $this->validator->validate($draft);
        $base = AgentSlugger::from($preferredSlug);
        if ($this->agents->slugTaken($ownerId, $base)) {
            $base = AgentSlugger::from($preferredSlug.'-imported');
        }
        $slug = $this->uniqueSlug($ownerId, $base);
        $promptId = $this->createInstructionPrompt($ownerId, $slug, $name, $instruction);
        $agent = new Agent($ownerId, $promptId, $slug, $name, $definition->toArray());
        $agent->setSource('' !== $source ? $source : Agent::SOURCE_IMPORT);
        if (null !== $description && '' !== trim($description)) {
            $agent->setDescription(trim($description));
        }
        if (null !== $icon && '' !== $icon) {
            $this->assertIcon($icon);
            $agent->setIcon($icon);
        }
        $this->agents->save($agent);

        return $agent;
    }

    public function publishedVersionNumber(Agent $agent): ?int
    {
        return $this->publishedVersion($agent)?->getVersion();
    }

    public function publishedVersion(Agent $agent): ?AgentVersion
    {
        $id = $agent->getPublishedVersionId();
        if (null === $id) {
            return null;
        }
        $version = $this->versions->find($id);

        return $version instanceof AgentVersion ? $version : null;
    }

    private function applyStatus(Agent $agent, string $status): void
    {
        if (Agent::STATUS_ARCHIVED === $status) {
            if (!$agent->hasPublishedVersion()) {
                throw new \InvalidArgumentException('Only a published assistant can be archived');
            }
            $agent->setStatus(Agent::STATUS_ARCHIVED);

            return;
        }
        if (Agent::STATUS_PUBLISHED === $status) {
            if (!$agent->hasPublishedVersion()) {
                throw new \InvalidArgumentException('This assistant has no published version');
            }
            $agent->setStatus(Agent::STATUS_PUBLISHED);

            return;
        }

        throw new \InvalidArgumentException('status must be archived or published');
    }

    private function ownerDisplayName(int $ownerId): string
    {
        $owner = $this->users->find($ownerId);

        return $owner instanceof User ? $this->serializer->displayName($owner) : '';
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
        $prompt->setTopic(Agent::TOPIC_PREFIX.$slug);
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

    /**
     * Published, routable assistants this user may use.
     *
     * @return list<Agent>
     */
    public function routableForUser(User $user): array
    {
        $out = [];
        foreach ($this->agents->findPublishedRoutable() as $agent) {
            if ($this->access->can($user, $agent, Permission::Use)) {
                $out[] = $agent;
            }
        }

        return $out;
    }
}
