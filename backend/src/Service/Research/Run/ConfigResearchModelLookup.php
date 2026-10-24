<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\AI\Service\ProviderRegistry;
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
 */
final readonly class ConfigResearchModelLookup implements ResearchModelLookup
{
    public function __construct(
        private ModelConfigService $models,
        private ModelRepository $repository,
        private ModelHealthRepository $health,
        private ConfigRepository $config,
        private GroupPolicyService $groups,
        private ProviderRegistry $providers,
    ) {
    }

    public function slot(int $userId, string $slot): ?ResearchModelChoice
    {
        $id = $this->models->getDefaultModel($slot, $userId);
        if (null === $id || $id < 1) {
            return null;
        }

        return $this->choice($userId, $id);
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

        return $this->choice($userId, $id);
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

    private function choice(int $userId, int $id): ?ResearchModelChoice
    {
        $model = $this->repository->find($id);
        if (!$model instanceof Model) {
            return null;
        }

        $meta = $model->getJson()['meta'] ?? [];
        $family = is_array($meta) ? strtolower((string) ($meta['family'] ?? '')) : '';

        return new ResearchModelChoice(
            id: $id,
            provider: strtolower($model->getService()),
            family: $family,
            allowed: $this->groups->isModelAllowed($userId, $id),
            healthy: $this->healthy($model),
            retired: $model->isRetired(),
            successorId: $model->getSuccessorId(),
            contextWindow: $model->getContextWindowTokens(),
        );
    }

    private function healthy(Model $model): bool
    {
        if (1 !== $model->getActive() || $model->isRetired()) {
            return false;
        }
        try {
            if (in_array((int) $model->getId(), $this->health->findOfflineModelIds(), true)) {
                return false;
            }
        } catch (\Throwable) {
            // A missing health table means nothing is known to be offline.
        }

        $available = array_map(strtolower(...), $this->providers->getAvailableProviders('chat'));
        if ([] === $available) {
            return true;
        }

        return in_array(strtolower($model->getService()), $available, true);
    }
}
