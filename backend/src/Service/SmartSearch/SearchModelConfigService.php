<?php

declare(strict_types=1);

namespace App\Service\SmartSearch;

use App\AI\Credential\ChatReadinessService;
use App\Entity\Model;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Service\ModelConfigService;

/**
 * The two system-wide search model slots. An empty slot inherits: the AI
 * tier from the tools model (which inherits the chat model), the index from
 * the instance embedding model. Search therefore works without any setup,
 * and an admin choice only ever narrows it.
 */
final readonly class SearchModelConfigService
{
    public const SLOT_AI = 'SEARCH';
    public const SLOT_EMBED = 'SEARCH_EMBED';

    private const GROUP = 'DEFAULTMODEL';
    private const SYSTEM_OWNER = 0;
    private const SLOT_TAGS = [
        self::SLOT_AI => 'chat',
        self::SLOT_EMBED => 'vectorize',
    ];

    public function __construct(
        private ConfigRepository $configRepository,
        private ModelRepository $models,
        private ModelConfigService $modelConfig,
        private ChatReadinessService $readiness,
    ) {
    }

    /**
     * The admin's choice for a slot, or null while it inherits. A choice
     * whose model was removed, deactivated or retagged counts as inherit.
     */
    public function selectedModelId(string $slot): ?int
    {
        $raw = $this->configRepository->getValue(self::SYSTEM_OWNER, self::GROUP, self::assertSlot($slot));
        if (null === $raw || !ctype_digit($raw)) {
            return null;
        }
        $model = $this->models->find((int) $raw);

        return null !== $model && $this->fitsSlot($model, $slot) ? (int) $model->getId() : null;
    }

    /** The model a slot would use with no admin choice. */
    public function inheritedModelId(string $slot, ?int $userId = null): ?int
    {
        return self::SLOT_AI === self::assertSlot($slot)
            ? $this->modelConfig->getToolsModelConfig($userId)['model_id']
            : $this->modelConfig->getDefaultModel('VECTORIZE');
    }

    public function effectiveModelId(string $slot, ?int $userId = null): ?int
    {
        return $this->selectedModelId($slot) ?? $this->inheritedModelId($slot, $userId);
    }

    public function aiModel(?int $userId): ?Model
    {
        $modelId = $this->effectiveModelId(self::SLOT_AI, $userId);

        return null === $modelId ? null : $this->models->find($modelId);
    }

    public function embedModelId(): ?int
    {
        return $this->effectiveModelId(self::SLOT_EMBED);
    }

    /** "name (service)" for any model, including ones the slot cannot offer. */
    public function label(?int $modelId): ?string
    {
        $model = null === $modelId ? null : $this->models->find($modelId);

        return null === $model ? null : sprintf('%s (%s)', $model->getName(), $model->getService());
    }

    /**
     * @return array{available: bool, reason: ?string}
     */
    public function availability(Model $model): array
    {
        return $this->readiness->modelAvailability($model->getService(), $model->getProviderId() ?: $model->getName());
    }

    /**
     * Models an admin can pick for a slot, each with whether it can answer now.
     *
     * @return list<array{id: int, name: string, service: string, available: bool, reason: ?string}>
     */
    public function options(string $slot): array
    {
        $options = [];
        foreach ($this->models->findByTag(self::SLOT_TAGS[self::assertSlot($slot)]) as $model) {
            if (1 !== $model->getActive() || null === $model->getId()) {
                continue;
            }
            $availability = $this->availability($model);
            $options[] = [
                'id' => $model->getId(),
                'name' => $model->getName(),
                'service' => $model->getService(),
                'available' => $availability['available'],
                'reason' => $availability['reason'],
            ];
        }

        return $options;
    }

    /**
     * Validates a choice without storing it.
     *
     * @throws \InvalidArgumentException with a message naming what is wrong
     */
    public function requireSelectable(string $slot, int $modelId): Model
    {
        $model = $this->models->find($modelId);
        if (null === $model || !$this->fitsSlot($model, self::assertSlot($slot))) {
            throw new \InvalidArgumentException(sprintf('Model %d cannot serve the search slot %s.', $modelId, $slot));
        }
        if (!$this->availability($model)['available']) {
            throw new \InvalidArgumentException(sprintf('Model %d is not available: its provider is not set up.', $modelId));
        }

        return $model;
    }

    /** Stores a choice; null returns the slot to inherit. */
    public function store(string $slot, ?int $modelId): void
    {
        $slot = self::assertSlot($slot);
        if (null === $modelId) {
            $this->configRepository->deleteValue(self::SYSTEM_OWNER, self::GROUP, $slot);

            return;
        }
        $this->configRepository->setValue(self::SYSTEM_OWNER, self::GROUP, $slot, (string) $modelId);
    }

    /**
     * Puts the index back on the model it used before a failed switch;
     * when that was the inherited model the slot inherits again.
     */
    public function restoreEmbedModel(int $modelId): void
    {
        $this->store(self::SLOT_EMBED, $modelId === $this->inheritedModelId(self::SLOT_EMBED) ? null : $modelId);
    }

    private function fitsSlot(Model $model, string $slot): bool
    {
        return 1 === $model->getActive() && self::SLOT_TAGS[$slot] === $model->getTag();
    }

    private static function assertSlot(string $slot): string
    {
        if (!array_key_exists($slot, self::SLOT_TAGS)) {
            throw new \InvalidArgumentException(sprintf('Unknown search model slot "%s".', $slot));
        }

        return $slot;
    }
}
