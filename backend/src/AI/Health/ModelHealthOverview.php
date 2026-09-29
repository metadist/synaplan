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
 *
 * Retired rows (BRETIREDON set) are reported in their own list and never
 * counted. The evaluator stops checking them, so whatever verdict is stored
 * predates the retirement and would show a dead model as "offline" — or a
 * superseded one as "online" — forever.
 *
 * Rows an operator switched off stay under their provider with their real
 * state, but are counted as `switchedOff` instead of by state and never need
 * attention.
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
     *     summary: array{total: int, online: int, degraded: int, offline: int, unconfigured: int, unknown: int, switchedOff: int, retired: int, needsAttention: int, lastCheck: int, autoDisableEnabled: bool, monitoringEnabled: bool},
     *     providers: list<array{name: string, displayName: string, needsAttention: int, models: list<array<string, mixed>>}>,
     *     retired: list<array{id: int, name: string, providerId: string, capability: string, provider: string, providerDisplayName: string, retiredOn: string, successorName: string|null}>
     * }
     */
    public function build(): array
    {
        /** @var list<Model> $models */
        $models = $this->models->findBy([], ['service' => 'ASC', 'tag' => 'ASC', 'name' => 'ASC']);
        $healthByModel = $this->healthRepository->findIndexedByModelId();
        $displayNames = $this->displayNames->all();

        $modelsById = [];
        foreach ($models as $model) {
            $modelsById[(int) $model->getId()] = $model;
        }

        $counts = array_fill_keys(array_map(static fn (ModelHealthState $s): string => $s->value, ModelHealthState::cases()), 0);
        $lastCheck = 0;
        $byProvider = [];
        $retired = [];
        $switchedOffCount = 0;
        $now = time();

        foreach ($models as $model) {
            $modelId = (int) $model->getId();

            // Grouped by the normalised key, not by BSERVICE itself: the
            // catalog holds both "Ollama" and "ollama", which would otherwise
            // render as two sections under the same heading. A re-check scoped
            // to this key still covers both, because the run matches services
            // case-insensitively.
            $service = ModelCatalog::normalizeProvider($model->getService());
            // Unregistered services (Jina/Cohere/Voyage rerank, …) are not in
            // the provider registry. The heading must be the normalised key —
            // matching $service — not BSERVICE casing.
            $displayName = $displayNames[$service] ?? $service;

            if ($model->isRetired()) {
                $successorId = $model->getSuccessorId();
                $successor = null !== $successorId ? ($modelsById[$successorId] ?? null) : null;

                $retired[] = [
                    'id' => $modelId,
                    'name' => $model->getName(),
                    'providerId' => $model->getProviderId(),
                    'capability' => $model->getTag(),
                    'provider' => $service,
                    'providerDisplayName' => $displayName,
                    'retiredOn' => $model->getRetiredOn()?->format('Y-m-d') ?? '',
                    'successorName' => $successor?->getName(),
                ];
                continue;
            }

            $health = $healthByModel[$modelId] ?? null;
            $counters = $this->recorder->snapshot($modelId);

            $state = $health?->getState() ?? ModelHealthState::Unknown;
            $lastCheck = max($lastCheck, $health?->getLastCheck() ?? 0);

            // Still listed with its real state, so a recovery is visible, but
            // counted apart: an operator's own decision is not a problem.
            $switchedOff = ModelHealth::isSwitchedOffByOperator($model, $health);
            $needsAttention = !$switchedOff && $state->needsAttention();
            if ($switchedOff) {
                ++$switchedOffCount;
            } else {
                ++$counts[$state->value];
            }

            $byProvider[$service] ??= [
                'name' => $service,
                'displayName' => $displayName,
                'needsAttention' => 0,
                'models' => [],
            ];
            if ($needsAttention) {
                ++$byProvider[$service]['needsAttention'];
            }

            $byProvider[$service]['models'][] = [
                'id' => $modelId,
                'name' => $model->getName(),
                'providerId' => $model->getProviderId(),
                'capability' => $model->getTag(),
                'state' => $state->value,
                'needsAttention' => $needsAttention,
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

        // Most recent retirement first: that is the one an operator is
        // likely looking for right after a deploy.
        usort($retired, static function (array $a, array $b): int {
            return [$b['retiredOn'], $a['providerDisplayName'], $a['name']] <=> [$a['retiredOn'], $b['providerDisplayName'], $b['name']];
        });

        return [
            'summary' => [
                'total' => count($models),
                'online' => $counts[ModelHealthState::Online->value],
                'degraded' => $counts[ModelHealthState::Degraded->value],
                'offline' => $counts[ModelHealthState::Offline->value],
                'unconfigured' => $counts[ModelHealthState::Unconfigured->value],
                'unknown' => $counts[ModelHealthState::Unknown->value],
                'switchedOff' => $switchedOffCount,
                'retired' => count($retired),
                'needsAttention' => $counts[ModelHealthState::Degraded->value] + $counts[ModelHealthState::Offline->value],
                'lastCheck' => $lastCheck,
                'autoDisableEnabled' => $this->config->isAutoDisableEnabled(),
                'monitoringEnabled' => $this->config->isEnabled(),
            ],
            'providers' => $providers,
            'retired' => $retired,
        ];
    }
}
