<?php

declare(strict_types=1);

namespace App\AI\Health;

use App\AI\Service\ProviderDisplayNames;
use App\Entity\Model;
use App\Entity\ModelHealth;
use App\Model\ModelCatalog;
use App\Repository\ModelHealthRepository;
use App\Repository\ModelRepository;

/**
 * Builds the payload behind the admin model status page.
 *
 * Reads only: the persisted verdict from BMODELHEALTH plus the live traffic
 * counters from Redis. Opening the page never triggers a provider call — a
 * status page that probes on render turns a browser refresh into API traffic,
 * and an operator watching an incident refreshes a lot.
 */
final readonly class ModelHealthOverview
{
    public function __construct(
        private ModelRepository $models,
        private ModelHealthRepository $healthRepository,
        private ModelHealthRecorder $recorder,
        private ModelHealthConfig $config,
        private ProviderDisplayNames $displayNames,
    ) {
    }

    /**
     * @return array{
     *     summary: array{total: int, online: int, degraded: int, offline: int, unconfigured: int, unknown: int, needsAttention: int, lastCheck: int, autoDisableEnabled: bool, monitoringEnabled: bool},
     *     providers: list<array{name: string, displayName: string, needsAttention: int, models: list<array<string, mixed>>}>
     * }
     */
    public function build(): array
    {
        /** @var list<Model> $models */
        $models = $this->models->findBy([], ['service' => 'ASC', 'tag' => 'ASC', 'name' => 'ASC']);
        $healthByModel = $this->healthRepository->findIndexedByModelId();
        $displayNames = $this->displayNames->all();

        $counts = array_fill_keys(array_map(static fn (ModelHealthState $s): string => $s->value, ModelHealthState::cases()), 0);
        $lastCheck = 0;
        $byProvider = [];
        $now = time();

        foreach ($models as $model) {
            $modelId = (int) $model->getId();
            $health = $healthByModel[$modelId] ?? null;
            $counters = $this->recorder->snapshot($modelId);

            $state = $health?->getState() ?? ModelHealthState::Unknown;
            ++$counts[$state->value];
            $lastCheck = max($lastCheck, $health?->getLastCheck() ?? 0);

            // Grouped by the normalised key, not by BSERVICE itself: the
            // catalog holds both "Ollama" and "ollama", which would otherwise
            // render as two sections under the same heading. A re-check scoped
            // to this key still covers both, because the run matches services
            // case-insensitively.
            $service = ModelCatalog::normalizeProvider($model->getService());
            $byProvider[$service] ??= [
                'name' => $service,
                // A catalog entry for a provider this build does not register
                // still has to show something, so the raw service is the fallback.
                'displayName' => $displayNames[$service] ?? $model->getService(),
                'needsAttention' => 0,
                'models' => [],
            ];
            if ($state->needsAttention()) {
                ++$byProvider[$service]['needsAttention'];
            }

            $byProvider[$service]['models'][] = [
                'id' => $modelId,
                'name' => $model->getName(),
                'providerId' => $model->getProviderId(),
                'capability' => $model->getTag(),
                'state' => $state->value,
                'reason' => $health?->getMessage() ?? '',
                'source' => $health?->getSource() ?? ModelHealth::SOURCE_PROBE,
                'lastCheck' => $health?->getLastCheck() ?? 0,
                'lastSuccess' => max($health?->getLastSuccess() ?? 0, $counters->lastSuccessAt),
                'lastFailure' => max($health?->getLastFailure() ?? 0, $counters->lastFailureAt),
                'successes' => $counters->successes,
                'failures' => $counters->failures,
                'errorRatePercent' => $counters->errorRatePercent(),
                'active' => 1 === $model->getActive(),
                'selectable' => 1 === $model->getSelectable(),
                'autoDisabled' => $health?->isAutoDisabled() ?? false,
                'exemptUntil' => null !== $health && $health->isSuppressed($now) ? $health->getSuppressUntil() : 0,
            ];
        }

        $providers = array_values($byProvider);
        // Providers with something wrong float to the top: an operator opening
        // this page is looking for the problem, not for an alphabet. Ties are
        // ordered by the label actually on screen, not by the internal key.
        usort($providers, static function (array $a, array $b): int {
            return [$b['needsAttention'], $a['displayName']] <=> [$a['needsAttention'], $b['displayName']];
        });

        return [
            'summary' => [
                'total' => count($models),
                'online' => $counts[ModelHealthState::Online->value],
                'degraded' => $counts[ModelHealthState::Degraded->value],
                'offline' => $counts[ModelHealthState::Offline->value],
                'unconfigured' => $counts[ModelHealthState::Unconfigured->value],
                'unknown' => $counts[ModelHealthState::Unknown->value],
                'needsAttention' => $counts[ModelHealthState::Degraded->value] + $counts[ModelHealthState::Offline->value],
                'lastCheck' => $lastCheck,
                'autoDisableEnabled' => $this->config->isAutoDisableEnabled(),
                'monitoringEnabled' => $this->config->isEnabled(),
            ],
            'providers' => $providers,
        ];
    }
}
