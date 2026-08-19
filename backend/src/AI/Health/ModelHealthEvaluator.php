<?php

declare(strict_types=1);

namespace App\AI\Health;

use App\AI\Health\Probe\ModelListProbeInterface;
use App\AI\Health\Probe\ModelListProbeRegistry;
use App\AI\Health\Probe\ProbeResult;
use App\AI\Service\ModelProbeResult;
use App\AI\Service\ProviderDisplayNames;
use App\Entity\Model;
use App\Entity\ModelHealth;
use App\Repository\ModelHealthRepository;
use App\Repository\ModelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns free catalog lookups and passive traffic counters into one verdict per
 * model, persists it, and alerts on the changes.
 *
 * Two evidence sources, in this order:
 *
 *   1. The provider's own catalog. Authoritative for "does this model still
 *      exist" and free, but blind to problems that only affect this account.
 *   2. The rolling traffic counters. They see the account-specific failures
 *      (exhausted quota, blocked region) that a catalog lookup never will.
 *
 * The catalog wins where it speaks, because a model the provider no longer
 * lists is gone no matter what the counters say.
 */
final readonly class ModelHealthEvaluator
{
    /**
     * Ceiling on per-model confirmations per provider per run. Reached only
     * when a listing parses but is wildly incomplete; without it, one malformed
     * response would turn into one request per catalogued model.
     */
    private const MAX_CONFIRMATIONS_PER_SERVICE = 25;

    /**
     * How long a conclusive per-model answer is reused. An hour keeps a retired
     * model from being re-probed on every run while still noticing a model that
     * comes back well before anyone would act on the status page.
     */
    private const PRESENCE_CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private ModelRepository $models,
        private ModelHealthRepository $healthRepository,
        private ModelListProbeRegistry $probes,
        private ModelHealthRecorder $recorder,
        private ModelHealthConfig $config,
        private ModelHealthAlerter $alerter,
        private ModelAutoDisabler $autoDisabler,
        private EntityManagerInterface $em,
        private CacheItemPoolInterface $cache,
        private ProviderDisplayNames $displayNames,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Run one full check.
     *
     * @param list<string> $onlyServices restrict the run to these services (case-insensitive)
     */
    public function run(bool $dryRun = false, array $onlyServices = []): ModelHealthRun
    {
        $verdicts = [];
        $skipped = [];
        $raised = [];
        $resolved = [];
        $budget = [];
        $now = time();

        $wanted = array_map(mb_strtolower(...), $onlyServices);

        foreach ($this->models->findAllServices() as $service) {
            if ([] !== $wanted && !in_array(mb_strtolower($service), $wanted, true)) {
                continue;
            }

            $catalogModels = $this->models->findByServiceIndexedByProviderId($service);
            if ([] === $catalogModels) {
                continue;
            }

            $probe = $this->probes->find($service);
            $result = null === $probe
                ? ProbeResult::skipped('No free catalog endpoint exists for this provider.')
                : $this->probeSafely($service, $probe);

            if ($result->isSkipped()) {
                $skipped[$service] = $result->message;
            }

            $serviceVerdicts = [];
            foreach ($catalogModels as $providerId => $rows) {
                // One confirmation per provider model id, not per BMODELS row:
                // the same id appears once per capability it is bound to.
                $presence = $this->presence($service, $probe, $result, (string) $providerId, $budget);

                foreach ($rows as $model) {
                    $verdict = $this->judge($service, $model, (string) $providerId, $result, $presence, $now);
                    if (!$dryRun) {
                        $verdict = $this->commit($verdict, $model, $now);
                    }
                    $serviceVerdicts[] = $verdict;
                }
            }

            $outcome = $this->reconcileAlerts($service, $serviceVerdicts, $result, $dryRun);
            $raised = array_merge($raised, $outcome['raised']);
            $resolved = array_merge($resolved, $outcome['resolved']);

            $verdicts = array_merge($verdicts, $serviceVerdicts);
        }

        if (!$dryRun) {
            $this->em->flush();
            $this->healthRepository->pruneOrphans();
        }

        return new ModelHealthRun($verdicts, $skipped, $raised, $resolved, $dryRun);
    }

    /**
     * A probe that throws must not abort the whole run — one broken provider
     * would otherwise hide the state of every other one.
     */
    private function probeSafely(string $service, ModelListProbeInterface $probe): ProbeResult
    {
        try {
            return $probe->probe($service);
        } catch (\Throwable $e) {
            $this->logger->warning('Model catalog probe threw', [
                'service' => $service,
                'error' => $e->getMessage(),
            ]);

            return ProbeResult::failed(FailureKind::Transient, 'Probe failed: '.$e->getMessage());
        }
    }

    /**
     * Settle whether a model missing from the listing is actually gone.
     *
     * An earlier version answered this with a heuristic: a listing was allowed
     * to retire models of a capability once it recognised any other model of
     * that capability. Measured against the live APIs, that was wrong in both
     * directions on our own catalog — it reported the three Imagen models as
     * retired (Google omits them from models.list but serves them, and
     * `GET /v1beta/models/imagen-4.0-generate-001` answers 200) while excusing
     * `grok-tts`, which xAI really had removed (404). The heuristic tracked how
     * our catalog is grouped, not what the provider serves, so it is gone and
     * the provider itself is asked instead.
     *
     * @param array<string, int> $budget per-service confirmation counter, by reference
     */
    private function presence(
        string $service,
        ?ModelListProbeInterface $probe,
        ProbeResult $result,
        string $providerId,
        array &$budget,
    ): ModelProbeResult {
        if (!$result->listingComplete) {
            return ModelProbeResult::Inconclusive;
        }
        if ($result->offers($providerId)) {
            return ModelProbeResult::Alive;
        }
        // Ollama and Cloudflare enumerate their whole surface in one call, so
        // absence there is already the answer and costs no extra request.
        if ($result->listingAuthoritative) {
            return ModelProbeResult::Gone;
        }
        if (null === $probe) {
            return ModelProbeResult::Inconclusive;
        }

        // This check runs every few minutes, but a model does not come back
        // from the dead that fast. Re-asking about a settled model on every run
        // would mean hundreds of pointless requests a day per retired model, so
        // a conclusive answer is remembered for a while. Recovery is still
        // noticed, just one cache period later instead of one run later.
        $cacheKey = 'model_health.presence.'.mb_strtolower($service).'.'.md5($providerId);
        $item = $this->cache->getItem($cacheKey);
        if ($item->isHit()) {
            $cached = $item->get();
            if ($cached instanceof ModelProbeResult) {
                return $cached;
            }
        }

        // A listing that is technically valid but wildly incomplete would
        // otherwise fan out into one request per catalogued model. Past the
        // ceiling we report "unknown", which is honest and harmless.
        $spent = $budget[$service] ?? 0;
        if ($spent >= self::MAX_CONFIRMATIONS_PER_SERVICE) {
            return ModelProbeResult::Inconclusive;
        }
        $budget[$service] = $spent + 1;

        try {
            $presence = $probe->confirm($service, $providerId);
        } catch (\Throwable $e) {
            $this->logger->warning('Model confirmation probe threw', [
                'service' => $service,
                'model' => $providerId,
                'error' => $e->getMessage(),
            ]);

            return ModelProbeResult::Inconclusive;
        }

        // Only conclusive answers are worth remembering. Caching "inconclusive"
        // would extend a rate limit into a blind spot.
        if (ModelProbeResult::Inconclusive !== $presence) {
            $item->set($presence);
            $item->expiresAfter(self::PRESENCE_CACHE_TTL_SECONDS);
            $this->cache->save($item);
        }

        return $presence;
    }

    /**
     * Decide the state of a single model.
     */
    private function judge(string $service, Model $model, string $providerId, ProbeResult $probe, ModelProbeResult $presence, int $now): ModelHealthVerdict
    {
        $modelId = (int) $model->getId();
        $counters = $this->recorder->snapshot($modelId);

        $make = fn (ModelHealthState $state, ?FailureKind $kind, string $message, string $source, bool $safeToDisable = false): ModelHealthVerdict => new ModelHealthVerdict(
            modelId: $modelId,
            service: $service,
            modelName: $model->getName(),
            providerId: $providerId,
            tag: $model->getTag(),
            state: $state,
            kind: $kind,
            message: $message,
            source: $source,
            safeToDisable: $safeToDisable,
        );

        // A provider nobody configured is not broken. Saying otherwise would
        // paint a self-hosted install red for the fifteen providers it never
        // set up, and an operator who learns to ignore red stops reading it.
        if ($probe->isSkipped()) {
            return $make(ModelHealthState::Unconfigured, null, $probe->message, ModelHealth::SOURCE_PROBE);
        }

        if ($probe->isFailed()) {
            $state = FailureKind::Credential === $probe->kind
                ? ModelHealthState::Offline
                : ModelHealthState::Degraded;

            return $make($state, $probe->kind, $probe->message, ModelHealth::SOURCE_PROBE);
        }

        // Only the provider's own rejection retires a model. This is the single
        // place allowed to emit Offline+Permanent from catalog evidence, and
        // ModelConfigService routes traffic away from exactly that combination,
        // so anything weaker than a confirmed Gone must not reach it.
        if (ModelProbeResult::Gone === $presence) {
            return $make(
                ModelHealthState::Offline,
                FailureKind::Permanent,
                sprintf('%s no longer serves this model.', $this->displayNames->forService($service)),
                ModelHealth::SOURCE_PROBE,
                safeToDisable: true,
            );
        }

        // Missing from the listing, and the provider would not say either way.
        // Fall through to the traffic counters and, failing those, report
        // honestly that we do not know rather than guessing in either direction.
        if (ModelProbeResult::Inconclusive === $presence && $probe->listingComplete && !$probe->offers($providerId) && $counters->isEmpty()) {
            return $make(
                ModelHealthState::Unknown,
                null,
                sprintf('%s does not list this model and did not confirm whether it still exists.', $this->displayNames->forService($service)),
                ModelHealth::SOURCE_PROBE,
            );
        }

        // The provider still offers it. Now ask real traffic whether it works
        // for THIS account — the part a catalog lookup can never answer.
        if ($counters->total() >= $this->config->minSampleSize()
            && $counters->errorRatePercent() >= $this->config->errorRatePercent()) {
            $state = FailureKind::Permanent === $counters->lastKind
                ? ModelHealthState::Offline
                : ModelHealthState::Degraded;

            return $make(
                $state,
                $counters->lastKind,
                trim(sprintf(
                    '%d%% of the last %d calls failed. %s',
                    $counters->errorRatePercent(),
                    $counters->total(),
                    $counters->lastMessage ?? ''
                )),
                ModelHealth::SOURCE_TRAFFIC,
                // Real calls failing permanently is the strongest evidence
                // there is — that is a model the provider actually rejects.
                safeToDisable: FailureKind::Permanent === $counters->lastKind,
            );
        }

        if (!$probe->listingComplete && $counters->isEmpty()) {
            // Nothing to go on: no catalog listing and no traffic. Reporting
            // "online" here would be a guess dressed up as a fact.
            return $make(ModelHealthState::Unknown, null, $probe->message, ModelHealth::SOURCE_PROBE);
        }

        return $make(
            ModelHealthState::Online,
            null,
            '',
            $counters->isEmpty() ? ModelHealth::SOURCE_PROBE : ModelHealth::SOURCE_TRAFFIC,
        );
    }

    /**
     * Aggregate this provider's verdicts into at most one alert per kind, and
     * send the all-clear for the kinds that recovered.
     *
     * @param list<ModelHealthVerdict> $verdicts
     *
     * @return array{raised: list<ModelHealthAlert>, resolved: list<ModelHealthAlert>}
     */
    private function reconcileAlerts(string $service, array $verdicts, ProbeResult $probe, bool $dryRun): array
    {
        $raised = [];
        $resolved = [];

        $credentialProblem = $probe->isFailed() && FailureKind::Credential === $probe->kind;

        $offline = array_values(array_filter(
            $verdicts,
            static fn (ModelHealthVerdict $v): bool => ModelHealthState::Offline === $v->state
        ));
        $degraded = array_values(array_filter(
            $verdicts,
            static fn (ModelHealthVerdict $v): bool => ModelHealthState::Degraded === $v->state
        ));

        $buckets = [
            ModelHealthAlert::KIND_CREDENTIAL => $credentialProblem ? $offline : [],
            // A credential failure already reports every model of the provider;
            // a second "models offline" alert about the same cause would just
            // double the noise.
            ModelHealthAlert::KIND_OFFLINE => $credentialProblem ? [] : $offline,
            ModelHealthAlert::KIND_DEGRADED => $degraded,
        ];

        foreach ($buckets as $kind => $affected) {
            if ([] !== $affected) {
                $alert = new ModelHealthAlert(
                    $kind,
                    $service,
                    array_map(static fn (ModelHealthVerdict $v): string => $v->modelName, $affected),
                    $affected[0]->message,
                    $this->displayNames->forService($service),
                );

                if ($dryRun || $this->alerter->raise($alert)) {
                    $raised[] = $alert;
                }
                continue;
            }

            if ($this->alerter->isOpen($service, $kind)) {
                $alert = new ModelHealthAlert($kind, $service, [], 'All models are answering again.', $this->displayNames->forService($service));
                if ($dryRun || $this->alerter->resolve($alert)) {
                    $resolved[] = $alert;
                }
            }
        }

        return ['raised' => $raised, 'resolved' => $resolved];
    }

    /**
     * Write the verdict to BMODELHEALTH and let the auto-disabler act on it.
     * Returns the verdict enriched with what actually happened to BACTIVE.
     */
    private function commit(ModelHealthVerdict $verdict, Model $model, int $now): ModelHealthVerdict
    {
        $health = $this->healthRepository->findOrCreate($verdict->modelId);
        $health->setState($verdict->state)
            ->setSource($verdict->source)
            ->setKind($verdict->kind?->value)
            ->setMessage('' === $verdict->message ? null : $verdict->message)
            ->setLastCheck($now)
            ->setUpdated($now);

        if (ModelHealthState::Online === $verdict->state) {
            $health->setLastSuccess($now);
        } elseif ($verdict->state->needsAttention()) {
            $health->setLastFailure($now);
        }

        $applied = $this->autoDisabler->apply($verdict, $model, $health, $now);

        return new ModelHealthVerdict(
            modelId: $verdict->modelId,
            service: $verdict->service,
            modelName: $verdict->modelName,
            providerId: $verdict->providerId,
            tag: $verdict->tag,
            state: $verdict->state,
            kind: $verdict->kind,
            message: $verdict->message,
            source: $verdict->source,
            safeToDisable: $verdict->safeToDisable,
            autoDisabled: $applied['disabled'],
            reEnabled: $applied['reEnabled'],
        );
    }
}
