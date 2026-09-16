<?php

declare(strict_types=1);

namespace App\Service\Agent;

use App\Entity\Agent;
use App\Entity\AgentVersion;
use App\Entity\Model;
use App\Entity\User;
use App\Model\ModelCatalog;
use App\Repository\AgentRepository;
use App\Repository\ModelRepository;
use App\Service\Iam\Permission;

/**
 * Makes published assistants addressable from outside the SPA: the
 * `assistant:<slug>` model alias of the OpenAI-compatible gateway (`api`
 * event) and the `list_assistants` / `synaplan_chat.agentId` MCP surface
 * (`mcp` and `desktop` events).
 *
 * Only the published snapshot counts — a draft never leaks through an
 * external channel, and a caller must hold `use` on the assistant.
 */
final readonly class AssistantAliasResolver
{
    public const PREFIX = 'assistant:';

    public const EVENT_API = 'api';
    public const EVENT_MCP = 'mcp';
    public const EVENT_DESKTOP = 'desktop';

    private const OWNED_BY = 'synaplan';

    public function __construct(
        private AgentConfig $agentConfig,
        private AgentRepository $agents,
        private AgentAccess $access,
        private AgentService $agentService,
        private ModelRepository $models,
    ) {
    }

    public function isAlias(?string $model): bool
    {
        return is_string($model) && str_starts_with($model, self::PREFIX);
    }

    /**
     * OpenAI `model` objects for `/v1/models`.
     *
     * @return list<array{id: string, object: string, created: int, owned_by: string}>
     */
    public function listAliases(User $user): array
    {
        $out = [];
        foreach ($this->usableWithEvent($user, [self::EVENT_API]) as $entry) {
            $out[] = [
                'id' => self::PREFIX.$entry['agent']->getSlug(),
                'object' => 'model',
                'created' => $entry['published']->getCreated(),
                'owned_by' => self::OWNED_BY,
            ];
        }

        return $out;
    }

    /**
     * Rows for the `list_assistants` MCP tool.
     *
     * @param list<string> $eventKinds
     *
     * @return list<array{slug: string, name: string, description: string|null, version: int, origin: string}>
     */
    public function listAssistants(User $user, array $eventKinds): array
    {
        $out = [];
        foreach ($this->usableWithEvent($user, $eventKinds) as $entry) {
            $agent = $entry['agent'];
            $card = $entry['card'];
            $out[] = [
                'slug' => $agent->getSlug(),
                'name' => is_string($card['name'] ?? null) && '' !== $card['name'] ? $card['name'] : $agent->getName(),
                'description' => is_string($card['description'] ?? null) ? $card['description'] : $agent->getDescription(),
                'version' => $entry['published']->getVersion(),
                'origin' => is_string($card['origin'] ?? null) ? $card['origin'] : 'mine',
            ];
        }

        return $out;
    }

    /**
     * Resolve an id or slug (optionally `assistant:`-prefixed) to a published
     * assistant the user may use through one of the given event kinds.
     *
     * @param list<string> $eventKinds
     */
    public function resolveUsable(User $user, string|int $ref, array $eventKinds): ?Agent
    {
        if (!$this->agentConfig->isEnabled((int) $user->getId())) {
            return null;
        }
        $agent = $this->findRef($user, $ref);
        if (!$agent instanceof Agent) {
            return null;
        }

        return null !== $this->usablePublished($user, $agent, $eventKinds) ? $agent : null;
    }

    /**
     * @return array{agent: Agent, instruction: string, chatModel: Model|null}|null
     */
    public function resolveAlias(User $user, string $model): ?array
    {
        if (!$this->isAlias($model) || !$this->agentConfig->isEnabled((int) $user->getId())) {
            return null;
        }
        $agent = $this->findRef($user, substr($model, strlen(self::PREFIX)));
        if (!$agent instanceof Agent) {
            return null;
        }
        $published = $this->usablePublished($user, $agent, [self::EVENT_API]);
        if (null === $published) {
            return null;
        }

        return [
            'agent' => $agent,
            'instruction' => $published->getPromptText(),
            'chatModel' => $this->chatModelOf($published->getDefinition()),
        ];
    }

    /**
     * Gallery entries (owned + shared) that are published, usable and expose
     * one of the event kinds.
     *
     * @param list<string> $eventKinds
     *
     * @return list<array{card: array<string, mixed>, agent: Agent, published: AgentVersion}>
     */
    private function usableWithEvent(User $user, array $eventKinds): array
    {
        if (!$this->agentConfig->isEnabled((int) $user->getId())) {
            return [];
        }
        $out = [];
        foreach ($this->agentService->galleryCards($user) as $card) {
            $agent = $this->agents->find((int) ($card['id'] ?? 0));
            if (!$agent instanceof Agent) {
                continue;
            }
            $published = $this->usablePublished($user, $agent, $eventKinds);
            if (null === $published) {
                continue;
            }
            $out[] = ['card' => $card, 'agent' => $agent, 'published' => $published];
        }

        return $out;
    }

    /**
     * The published snapshot when the user may use the assistant and its
     * published triggers enable one of the event kinds; null otherwise.
     *
     * @param list<string> $eventKinds
     */
    private function usablePublished(User $user, Agent $agent, array $eventKinds): ?AgentVersion
    {
        if ($agent->isArchived() || !$this->access->can($user, $agent, Permission::Use)) {
            return null;
        }
        $published = $this->agentService->publishedVersion($agent);
        if (!$published instanceof AgentVersion) {
            return null;
        }

        return $this->hasEnabledEvent($published->getDefinition(), $eventKinds) ? $published : null;
    }

    private function findRef(User $user, string|int $ref): ?Agent
    {
        if (is_int($ref) || ctype_digit($ref)) {
            $agent = $this->agents->find((int) $ref);

            return $agent instanceof Agent ? $agent : null;
        }
        $slug = str_starts_with($ref, self::PREFIX) ? substr($ref, strlen(self::PREFIX)) : $ref;
        foreach ($this->agents->findPublishedBySlug($slug) as $candidate) {
            if ($this->access->can($user, $candidate, Permission::Use)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $definition
     * @param list<string>         $eventKinds
     */
    private function hasEnabledEvent(array $definition, array $eventKinds): bool
    {
        $events = is_array($definition['triggers']['events'] ?? null) ? $definition['triggers']['events'] : [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $kind = (string) ($event['kind'] ?? '');
            $enabled = !array_key_exists('enabled', $event) || (bool) $event['enabled'];
            if ($enabled && in_array($kind, $eventKinds, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function chatModelOf(array $definition): ?Model
    {
        $chatKey = is_array($definition['models'] ?? null) ? ($definition['models']['chat'] ?? null) : null;
        if (!is_string($chatKey) || '' === $chatKey) {
            return null;
        }
        $bid = ModelCatalog::findBidByKey($chatKey);
        if (null === $bid) {
            return null;
        }
        $found = $this->models->find($bid);

        return $found instanceof Model ? $found : null;
    }
}
