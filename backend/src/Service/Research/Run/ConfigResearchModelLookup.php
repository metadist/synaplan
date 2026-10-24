<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Entity\Model;
use App\Model\ModelCatalog;
use App\Repository\ConfigRepository;
use App\Repository\ModelHealthRepository;
use App\Repository\ModelRepository;
use App\Service\Iam\Policy\GroupPolicyService;
use App\Service\ModelConfigService;

/**
 * Slot bindings from BCONFIG, with the group allow-list and a provider that
 * can actually be called. An unbound READ or VERIFY slot stays empty so the
 * resolver can walk the fallback chain. ModelConfigService is left unchanged.
 *
 * Every research role is a chat call, so a row with any other tag is never
 * offered — a Desktop hint naming a speech or embedding model resolves to
 * nothing instead of routing a chat request at it.
 */
final readonly class ConfigResearchModelLookup implements ResearchModelLookup
{
    private const CHAT_TAG = 'chat';

    public function __construct(
        private ModelConfigService $models,
        private ModelRepository $repository,
        private ModelHealthRepository $health,
        private ConfigRepository $config,
        private GroupPolicyService $groups,
    ) {
    }

    public function slot(int $userId, string $slot): ?ResearchModelChoice
    {
        $id = $this->models->getDefaultModel($slot, $userId);
        if (null === $id || $id < 1) {
            return null;
        }

        // getDefaultModel() already applied the allow-list, and a locked
        // instance default is the one model the member may use even when the
        // allow-list does not name it (#2103). Checking again would drop it.
        return $this->choice($userId, $id, allowListChecked: true);
    }

    public function byKey(int $userId, string $catalogKey): ?ResearchModelChoice
    {
        $id = ModelCatalog::findBidByKey($catalogKey);
        if (null === $id) {
            return null;
        }

        return $this->byId($userId, $id);
    }

    public function byId(int $userId, int $id): ?ResearchModelChoice
    {
        if ($id < 1) {
            return null;
        }

        return $this->choice($userId, $id, allowListChecked: false);
    }

    public function verifyCandidates(int $userId): array
    {
        $raw = $this->config->getValue(0, 'RESEARCH', 'VERIFY_CANDIDATES');
        if (!is_string($raw) || '' === $raw) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $choices = [];
        foreach ($decoded as $key) {
            if (!is_string($key)) {
                continue;
            }
            $choice = $this->byKey($userId, $key);
            if (null !== $choice) {
                $choices[] = $choice;
            }
        }

        return $choices;
    }

    private function choice(int $userId, int $id, bool $allowListChecked): ?ResearchModelChoice
    {
        $model = $this->repository->find($id);
        if (!$model instanceof Model || self::CHAT_TAG !== strtolower($model->getTag())) {
            return null;
        }

        $meta = $model->getJson()['meta'] ?? [];
        $family = is_array($meta) ? strtolower((string) ($meta['family'] ?? '')) : '';

        return new ResearchModelChoice(
            id: $id,
            provider: strtolower($model->getService()),
            family: $family,
            allowed: $allowListChecked || $this->groups->isModelAllowed($userId, $id),
            healthy: $this->healthy($model, $id),
            retired: $model->isRetired(),
            successorId: $model->getSuccessorId(),
            contextWindow: $model->getContextWindowTokens(),
        );
    }

    /**
     * Same answer generation uses: active row, a provider with credentials and,
     * for Ollama, a model that is actually pulled. A model the health monitor
     * reports permanently offline is skipped on top.
     */
    private function healthy(Model $model, int $id): bool
    {
        if ($model->isRetired() || !$this->models->isConfiguredModelUsable($id)) {
            return false;
        }

        try {
            return !in_array($id, $this->health->findOfflineModelIds(), true);
        } catch (\Throwable) {
            // A missing health table means nothing is known to be offline.
            return true;
        }
    }
}
