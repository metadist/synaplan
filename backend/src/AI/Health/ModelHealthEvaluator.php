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
 *
 * Rows that already carry BRETIREDON are skipped: a recorded retirement is
 * expected to be missing, so probing it again is wasted work and alerting on
 * it trains operators to ignore the mail. Operator-disabled rows without a
 * date stay in the check so a recovery shows, but never page anyone.
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
     * @param list<string> $onlyServices       restrict the run to these services (case-insensitive)
     * @param bool         $freshConfirmations ignore a remembered per-model answer and ask again
     */
    public function run(bool $dryRun = false, array $onlyServices = [], bool $freshConfirmations = false): ModelHealthRun
    {
        $verdicts = [];
        $skipped = [];
        $raised = [];
        $resolved = [];
        $budget = [];
        $streaks = [];
        $now = time();

        $wanted = array_map(mb_strtolower(...), $onlyServices);
        $seen = [];
        $healthByModel = $this->healthRepository->findIndexedByModelId();

        foreach ($this->models->findAllServices() as $service) {
            $serviceKey = mb_strtolower($service);
            if ([] !== $wanted && !in_array($serviceKey, $wanted, true)) {
                continue;
            }
            // BSERVICE can hold "Ollama" (catalog) and "ollama" (import); skip duplicates.
            if (isset($seen[$serviceKey])) {
                continue;
            }
            $seen[$serviceKey] = true;

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
            $activeByKind = [];
            foreach ($catalogModels as $providerId => $rows) {
                // Retired rows are skipped; operator-disabled rows stay in the
                // check so recovery shows, but never raise alerts.
                $liveRows = array_values(array_filter(
                    $rows,
                    static fn (Model $model): bool => !$model->isRetired()
                ));
                if ([] === $liveRows) {
                    continue;
                }

                // One confirmation per provider model id, not per BMODELS row.
                $presence = $this->presence($service, $probe, $result, (string) $providerId, $budget, $freshConfirmations);

                foreach ($liveRows as $model) {
                    $existing = $healthByModel[(int) $model->getId()] ?? null;
                    if (null !== $existing && $existing->isAlertActive() && null !== $existing->getLastAlertedKind() && '' !== $existing->getLastAlertedKind()) {
                        $activeByKind[$existing->getLastAlertedKind()][(int) $model->getId()] = $model->getName();
                    }
                    $switchedOff = ModelHealth::isSwitchedOffByOperator($model, $existing);
                    $verdict = $this->judge($service, $model, (string) $providerId, $result, $presence, $switchedOff, $existing, null !== $probe);
                    $streak = self::nextStreak($existing, $verdict, $result);
                    $streaks[$verdict->modelId] = $streak;
                    if (!$dryRun) {
                        $verdict = $this->commit($verdict, $model, $now, $streak);
                    }
                    $serviceVerdicts[] = $verdict;
                }
            }

            $outcome = $this->reconcileAlerts($service, $serviceVerdicts, $result, $streaks, $activeByKind, $dryRun);
            if (!$dryRun) {
                $this->clearRecoveredAlerts($serviceVerdicts, $now);
            }
            $raised = array_merge($raised, $outcome['raised']);
            $resolved = array_merge($resolved, $outcome['resolved']);

            $verdicts = array_merge($verdicts, $serviceVerdicts);
        }

        if (!$dryRun) {
            $this->em->flush();
            $this->healthRepository->pruneOrphans();
            $this->healthRepository->pruneRetired();
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
        bool $freshConfirmations = false,
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
        // A scheduled run reuses the last answer. A person who just pressed
        // "check again" needs the provider's current answer, not the one
        // stored up to an hour ago.
        if (!$freshConfirmations && $item->isHit()) {
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
    private function judge(
        string $service,
        Model $model,
        string $providerId,
        ProbeResult $probe,
        ModelProbeResult $presence,
        bool $switchedOff,
        ?ModelHealth $existing = null,
        bool $hasProbe = true,
    ): ModelHealthVerdict {
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
            switchedOff: $switchedOff,
        );

        // A provider nobody configured is not broken. Preserve ImportedModelListingCheck
        // verdicts for probe-less import services (OpenAICompatible) so a manual
        // refresh or unreachable import endpoint never wipes them (C7).
        if ($probe->isSkipped()) {
            return $this->judgeSkipped($probe, $existing, $hasProbe, $make);
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

        $minSamples = $this->config->minSampleSize();
        if ($counters->total() >= $minSamples && $counters->errorRatePercent() >= $this->config->errorRatePercent()) {
            $state = FailureKind::Permanent === $counters->lastKind
                ? ModelHealthState::Offline
                : ModelHealthState::Degraded;

            return $make(
                $state,
                $counters->lastKind,
                trim(sprintf('%d%% of the last %d calls failed. %s', $counters->errorRatePercent(), $counters->total(), $counters->lastMessage ?? '')),
                ModelHealth::SOURCE_TRAFFIC,
                safeToDisable: FailureKind::Permanent === $counters->lastKind,
            );
        }

        if (!$probe->listingComplete && $counters->total() < $minSamples) {
            return $this->judgeInconclusiveCatalog($probe, $existing, $make);
        }

        return $make(
            ModelHealthState::Online,
            null,
            '',
            $counters->total() >= $minSamples ? ModelHealth::SOURCE_TRAFFIC : ModelHealth::SOURCE_PROBE,
        );
    }

    /**
     * @param \Closure(ModelHealthState, ?FailureKind, string, string, bool=): ModelHealthVerdict $make
     */
    private function judgeSkipped(ProbeResult $probe, ?ModelHealth $existing, bool $hasProbe, \Closure $make): ModelHealthVerdict
    {
        if (!$hasProbe && null !== $existing && ModelHealth::SOURCE_LISTING === $existing->getSource()) {
            return $make(
                $existing->getState(),
                FailureKind::tryFrom((string) $existing->getKind()),
                $existing->getMessage() ?? '',
                ModelHealth::SOURCE_LISTING,
            );
        }

        return $make(ModelHealthState::Unconfigured, null, $probe->message, ModelHealth::SOURCE_PROBE);
    }

    /**
     * No catalog listing (for example HTTP 429 on `/models` or a credential-only
     * probe) and fewer than MIN_SAMPLE_SIZE real calls: keep a confirmed
     * Offline+Permanent verdict so live routing keeps avoiding dead models, and
     * report Unknown otherwise rather than guessing Online from noisy samples.
     *
     * @param \Closure(ModelHealthState, ?FailureKind, string, string, bool=): ModelHealthVerdict $make
     */
    private function judgeInconclusiveCatalog(ProbeResult $probe, ?ModelHealth $existing, \Closure $make): ModelHealthVerdict
    {
        if (null !== $existing
            && ModelHealthState::Offline === $existing->getState()
            && FailureKind::Permanent->value === $existing->getKind()) {
            return $make(
                ModelHealthState::Offline,
                FailureKind::Permanent,
                $existing->getMessage() ?? $probe->message,
                $existing->getSource(),
            );
        }

        return $make(ModelHealthState::Unknown, null, $probe->message, ModelHealth::SOURCE_PROBE);
    }

    /**
     * Aggregate this provider's verdicts into at most one alert per kind, and
     * send the all-clear for the kinds that recovered.
     *
     * @param list<ModelHealthVerdict>         $verdicts
     * @param array<int, int>                  $streaks      model id => consecutive failing checks
     * @param array<string, array<int,string>> $activeByKind alert kind => (model id => name) latched in DB before this run
     *
     * @return array{raised: list<ModelHealthAlert>, resolved: list<ModelHealthAlert>}
     */
    private function reconcileAlerts(string $service, array $verdicts, ProbeResult $probe, array $streaks, array $activeByKind, bool $dryRun): array
    {
        $raised = [];
        $resolved = [];

        $credentialProblem = $probe->isFailed() && FailureKind::Credential === $probe->kind;

        $offline = array_values(array_filter(
            $verdicts,
            static fn (ModelHealthVerdict $v): bool => $v->needsAttention() && ModelHealthState::Offline === $v->state
        ));
        $degraded = array_values(array_filter(
            $verdicts,
            static fn (ModelHealthVerdict $v): bool => $v->needsAttention() && ModelHealthState::Degraded === $v->state
        ));

        $buckets = [
            ModelHealthAlert::KIND_CREDENTIAL => $credentialProblem ? $offline : [],
            // A credential failure already reports every model of the provider;
            // a second "models offline" alert about the same cause would just
            // double the noise.
            ModelHealthAlert::KIND_OFFLINE => $credentialProblem ? [] : $offline,
            ModelHealthAlert::KIND_DEGRADED => $degraded,
        ];

        $notHealthy = [];
        $anyUnknown = false;
        foreach ($verdicts as $verdict) {
            if ($verdict->needsAttention()) {
                $notHealthy[$verdict->modelId] = true;
            } elseif (!$verdict->switchedOff && ModelHealthState::Unknown === $verdict->state) {
                $notHealthy[$verdict->modelId] = true;
                $anyUnknown = true;
            }
        }

        foreach ($buckets as $kind => $affected) {
            // Quiet models and models below the consecutive-failure threshold
            // stay in $affected so a broken model never looks like a recovery.
            $fresh = $this->freshVerdicts($affected, $kind, $streaks);
            if ([] !== $fresh) {
                $alert = new ModelHealthAlert(
                    $kind,
                    $service,
                    array_map(static fn (ModelHealthVerdict $v): string => $v->modelName, $fresh),
                    $fresh[0]->message,
                    $this->displayNames->forService($service),
                    array_map(static fn (ModelHealthVerdict $v): int => $v->modelId, $fresh),
                );

                if ($dryRun || $this->alerter->raise($alert)) {
                    $raised[] = $alert;
                }
                continue;
            }

            // A model that moved to a worse kind or is currently unknown (for
            // example during a rate-limited catalog check) has not recovered.
            if ([] !== $affected) {
                continue;
            }

            $redisOpen = $this->alerter->openIncident($service, $kind);
            $dbOpen = $activeByKind[$kind] ?? null;
            $open = $redisOpen ?? $dbOpen;
            $canResolve = null !== $open && ([] === $open ? !$anyUnknown : [] === array_intersect_key($open, $notHealthy));
            if ($canResolve) {
                $fallbackNames = null === $redisOpen && null !== $dbOpen ? array_values($dbOpen) : [];
                $fallbackIds = null === $redisOpen && null !== $dbOpen ? array_keys($dbOpen) : [];
                $alert = new ModelHealthAlert(
                    $kind,
                    $service,
                    $fallbackNames,
                    'All models are answering again.',
                    $this->displayNames->forService($service),
                    $fallbackIds,
                );
                if ($dryRun || $this->alerter->resolve($alert)) {
                    $resolved[] = $alert;
                }
            }
        }

        return ['raised' => $raised, 'resolved' => $resolved];
    }

    /**
     * Models that have failed consecutively at least to the configured
     * threshold and are allowed to alert right now.
     *
     * @param list<ModelHealthVerdict> $verdicts
     * @param array<int, int>          $streaks
     *
     * @return list<ModelHealthVerdict>
     */
    private function freshVerdicts(array $verdicts, string $kind, array $streaks): array
    {
        $threshold = $this->config->consecutiveFailures();
        $sustained = array_values(array_filter(
            $verdicts,
            static fn (ModelHealthVerdict $verdict): bool => ($streaks[$verdict->modelId] ?? 0) >= $threshold,
        ));
        if ([] === $sustained) {
            return [];
        }

        $due = array_flip($this->alerter->dueModelIds(array_map(
            static fn (ModelHealthVerdict $verdict): int => $verdict->modelId,
            $sustained,
        ), $kind));

        return array_values(array_filter(
            $sustained,
            static fn (ModelHealthVerdict $verdict): bool => isset($due[$verdict->modelId]),
        ));
    }

    private static function nextStreak(?ModelHealth $existing, ModelHealthVerdict $verdict, ProbeResult $probe): int
    {
        $previous = $existing?->getConsecutiveFailures() ?? 0;

        if ($verdict->switchedOff || ModelHealthState::Online === $verdict->state || ModelHealthState::Unconfigured === $verdict->state) {
            return 0;
        }

        if (!$verdict->needsAttention()) {
            return $previous;
        }

        // A preserved listing/probe verdict on an inconclusive catalog check
        // did not observe a new failure on this tick.
        if (ModelHealth::SOURCE_LISTING === $verdict->source
            || (!$probe->isFailed() && !$probe->listingComplete && ModelHealth::SOURCE_PROBE === $verdict->source)) {
            return $previous;
        }

        return $previous + 1;
    }

    /**
     * Clear active-alert latches after alert reconciliation so DB-backed open
     * incidents can still resolve when Redis has expired, while individual
     * recovered or operator-switched-off models anchor their quiet window now.
     *
     * @param list<ModelHealthVerdict> $verdicts
     */
    private function clearRecoveredAlerts(array $verdicts, int $now): void
    {
        foreach ($verdicts as $verdict) {
            if ($verdict->switchedOff
                || ModelHealthState::Online === $verdict->state
                || ModelHealthState::Unconfigured === $verdict->state) {
                $this->healthRepository->findOrCreate($verdict->modelId)->clearActiveAlert($now);
            }
        }
    }

    /**
     * Write the verdict to BMODELHEALTH and let the auto-disabler act on it.
     * Returns the verdict enriched with what actually happened to BACTIVE.
     */
    private function commit(ModelHealthVerdict $verdict, Model $model, int $now, int $streak): ModelHealthVerdict
    {
        $health = $this->healthRepository->findOrCreate($verdict->modelId);
        $health->setState($verdict->state)
            ->setSource($verdict->source)
            ->setKind($verdict->kind?->value)
            ->setMessage('' === $verdict->message ? null : $verdict->message)
            ->setLastCheck($now)
            ->setConsecutiveFailures($streak)
            ->setUpdated($now);

        if (ModelHealthState::Online === $verdict->state) {
            $health->setLastSuccess($now);
        } elseif ($verdict->needsAttention()) {
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
            switchedOff: $verdict->switchedOff,
        );
    }
}
