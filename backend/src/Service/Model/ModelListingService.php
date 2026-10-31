<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\AI\Credential\ChatReadinessService;
use App\AI\Credential\ProviderKeyCatalog;
use App\AI\Provider\ReasoningLevelCatalog;
use App\AI\Service\ProviderRegistry;
use App\Entity\Model;
use App\Entity\User;
use App\Model\ModelCatalog;
use App\Repository\GroupRepository;
use App\Repository\ModelRepository;
use App\Service\Config\LayeredConfigResolver;
use App\Service\Iam\Policy\GroupPolicyService;
use App\Service\ModelConfigService;
use App\Service\Usage\CostCalculationService;

/**
 * The model pickers of the AI Models page (GET /api/v1/config/models).
 *
 * Lists every active model this installation can run, grouped by the
 * capability it can be bound to, the provider list, and — for members of a
 * group with an allow-list — which pickers are restricted and by whom.
 */
final readonly class ModelListingService
{
    public function __construct(
        private ModelRepository $modelRepository,
        private ChatReadinessService $chatReadiness,
        private ProviderRegistry $providerRegistry,
        private ModelConfigService $modelConfigService,
        private ProviderKeyCatalog $keyCatalog,
        private ?CostCalculationService $costCalculationService = null,
        private ?GroupPolicyService $groupPolicyService = null,
        private ?LayeredConfigResolver $layeredConfigResolver = null,
        private ?GroupRepository $groupRepository = null,
    ) {
    }

    /**
     * @param bool $includeUnavailable also list models whose provider is not configured, flagged (admin views)
     * @param bool $applyGroupPolicy   hold the user to their group allow-list (everyone but admins)
     *
     * @return array{models: array<string, list<array<string, mixed>>>, providers: list<array{name: string, displayName: string, available: bool, requiresKey: bool}>, restricted: list<string>, groupLimits: array{names: list<string>, combined: bool}}
     */
    public function forUser(User $user, bool $includeUnavailable, bool $applyGroupPolicy): array
    {
        $availability = $this->chatReadiness->providerAvailability();
        $grouped = PickerCapabilities::group($this->rows($availability, $includeUnavailable));
        $providers = $this->providers($availability);

        $restricted = [];
        if (null !== $this->groupPolicyService && $applyGroupPolicy) {
            $userId = (int) $user->getId();
            foreach ($grouped as $capability => $rows) {
                if ($this->groupPolicyService->rowsAreRestricted($userId, $rows)) {
                    $restricted[] = $capability;
                }
                $filtered = $this->groupPolicyService->filterModelsByAllowList($userId, $rows);
                $grouped[$capability] = $this->withLockedDefaultVisible($filtered, $rows, $capability, $userId);
            }
        }

        return [
            'models' => $grouped,
            'providers' => $providers,
            'restricted' => $restricted,
            'groupLimits' => $this->groupLimitSummary((int) $user->getId(), $restricted),
        ];
    }

    /**
     * @param array<string, bool> $availability provider name => usable here
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $availability, bool $includeUnavailable): array
    {
        $rows = [];
        foreach ($this->modelRepository->findBy(['active' => 1], ['quality' => 'DESC', 'rating' => 'DESC']) as $model) {
            if ($model->isHiddenBecauseFree()) {
                continue;
            }

            // Rerank models are configured on the Reranking admin tab only and
            // carry no picker capability: the catch-all picker rule would
            // scatter them across every dropdown. Their cloud providers are
            // also unknown to the provider registry, so modelAvailability()
            // would call them available without a key.
            if ('rerank' === strtolower($model->getTag())) {
                continue;
            }

            ['available' => $available, 'reason' => $reason] = $this->chatReadiness->modelAvailability($model->getService(), $model->getProviderId(), $availability);
            if (!$available && !$includeUnavailable) {
                continue;
            }

            $rows[] = $this->row($model, $available, $reason);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Model $model, bool $available, ?string $unavailableReason): array
    {
        $row = [
            'id' => $model->getId(),
            'service' => $model->getService(),
            'name' => $model->getName(),
            'providerId' => $model->getProviderId(),
            'description' => $model->getDescription(),
            'quality' => $model->getQuality(),
            'rating' => $model->getRating(),
            'tag' => strtoupper($model->getTag()),
            'isSystemModel' => $model->isSystemModel(),
            'features' => $model->getFeatures(),
            'priceIn' => $model->getPriceIn(),
            'priceOut' => $model->getPriceOut(),
            'priceKnown' => $model->isPriceKnown(),
            'available' => $available,
            'unavailableReason' => $unavailableReason,
        ];

        $endpoint = $model->getJson()['endpoint'] ?? null;
        if (\is_string($endpoint) && '' !== $endpoint) {
            $row['endpoint'] = $endpoint;
        }

        $capabilityPrices = $this->costCalculationService?->imageCapabilityPrices($model);
        if (null !== $capabilityPrices) {
            $row['capabilityPrices'] = $capabilityPrices;
        }

        $reasoningLevels = ReasoningLevelCatalog::levels($model->getService(), $model->getProviderId(), $model->getFeatures());
        if (null !== $reasoningLevels) {
            $row['reasoningLevels'] = $reasoningLevels;
            $row['reasoningEffortDefault'] = ReasoningLevelCatalog::defaultLevel($reasoningLevels, $model->getJson());
        }

        return $row;
    }

    /**
     * @param array<string, bool> $availability
     *
     * @return list<array{name: string, displayName: string, available: bool, requiresKey: bool}>
     */
    private function providers(array $availability): array
    {
        $providers = [];
        foreach ($this->providerRegistry->getUniqueProviders() as $name => $provider) {
            $key = ModelCatalog::normalizeProvider((string) $name);
            if ('test' === $key) {
                continue;
            }
            $providers[] = [
                'name' => $key,
                'displayName' => $provider->getDisplayName(),
                'available' => $availability[$key] ?? false,
                'requiresKey' => $this->keyCatalog->has($key),
            ];
        }
        usort($providers, static fn (array $a, array $b): int => strcasecmp($a['displayName'], $b['displayName']));

        return $providers;
    }

    /**
     * Names of the groups whose allow-lists restrict this user.
     *
     * @param list<string> $restricted
     *
     * @return array{names: list<string>, combined: bool}
     */
    private function groupLimitSummary(int $userId, array $restricted): array
    {
        if ([] === $restricted || null === $this->layeredConfigResolver) {
            return ['names' => [], 'combined' => false];
        }

        $ids = $this->layeredConfigResolver->modelAllowListGroupIds($userId);
        if ([] === $ids) {
            return ['names' => [], 'combined' => false];
        }

        $byId = [];
        foreach ($this->groupRepository?->findByIds($ids) ?? [] as $group) {
            if (null !== $group->getId()) {
                $byId[(int) $group->getId()] = $group->getName();
            }
        }
        $names = [];
        foreach ($ids as $id) {
            if (isset($byId[$id]) && '' !== $byId[$id]) {
                $names[] = $byId[$id];
            }
        }

        return ['names' => $names, 'combined' => count($names) > 1];
    }

    /**
     * A locked instance default is the model that answers, even when the
     * allow-list would hide it. Put that row back so the settings screen
     * can name it.
     *
     * @param list<array<string, mixed>> $filtered
     * @param list<array<string, mixed>> $unfiltered
     *
     * @return list<array<string, mixed>>
     */
    private function withLockedDefaultVisible(array $filtered, array $unfiltered, string $capability, int $userId): array
    {
        if (null === $this->layeredConfigResolver || !$this->layeredConfigResolver->isLocked('DEFAULTMODEL', $capability, $userId)) {
            return $filtered;
        }

        $lockedId = $this->modelConfigService->reportedDefault($capability, $userId)['id'];
        if (null === $lockedId) {
            return $filtered;
        }
        foreach ($filtered as $row) {
            if ((int) ($row['id'] ?? 0) === $lockedId) {
                return $filtered;
            }
        }
        foreach ($unfiltered as $row) {
            if ((int) ($row['id'] ?? 0) === $lockedId) {
                $filtered[] = $row;

                return $filtered;
            }
        }

        return $filtered;
    }
}
