<?php

declare(strict_types=1);

namespace App\AI\Health;

use App\AI\Import\DiscoveryResult;
use App\AI\Import\ModelDiscovererInterface;
use App\AI\Import\UnknownImportSourceException;
use App\Entity\Model;
use App\Entity\ModelHealth;
use App\Repository\ModelHealthRepository;
use App\Repository\ModelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Re-lists each import source and flags imported models the source no longer
 * offers, so a model deleted upstream stops being offered here too.
 *
 * Runs inside the scheduled `app:model:health-check`. It reuses
 * {@see ModelAutoDisabler} for the actual soft-disable, so the same provenance
 * rules apply: only auto-disabled rows are auto-restored, operator toggles win,
 * and nothing is switched off unless MODELHEALTH.AUTO_DISABLE_ENABLED is on.
 *
 * C7 is the load-bearing rule: an **unreachable** source marks nothing. Only a
 * successful listing that omits a model counts as evidence it is gone —
 * otherwise a brief outage would retire every imported model at once.
 */
final readonly class ImportedModelListingCheck
{
    private const MISSING_MESSAGE = 'not offered by endpoint';

    public function __construct(
        private ModelRepository $models,
        private ModelDiscovererInterface $discovery,
        private ModelHealthRepository $healthRepository,
        private ModelAutoDisabler $autoDisabler,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{checkedSources: int, unreachable: int, markedOffline: int, restored: int}
     */
    public function run(bool $dryRun = false): array
    {
        $bySource = $this->importedModelsBySource();
        $now = time();
        $checked = 0;
        $unreachable = 0;
        $markedOffline = 0;
        $restored = 0;

        foreach ($bySource as $source => $rows) {
            $result = $this->discoverSafely($source);
            if (null === $result || !$result->ok) {
                // Endpoint deleted or unreachable: report nothing (C7).
                ++$unreachable;
                continue;
            }
            ++$checked;

            $offered = [];
            foreach ($result->models as $discovered) {
                $offered[$discovered->providerId] = true;
            }

            foreach ($rows as $model) {
                $present = isset($offered[$model->getProviderId()]);
                if ($dryRun) {
                    continue;
                }
                $applied = $this->reconcile($model, $present, $now);
                $markedOffline += $applied['disabled'] ? 1 : 0;
                $restored += $applied['reEnabled'] ? 1 : 0;
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        return [
            'checkedSources' => $checked,
            'unreachable' => $unreachable,
            'markedOffline' => $markedOffline,
            'restored' => $restored,
        ];
    }

    /**
     * @return array{disabled: bool, reEnabled: bool}
     */
    private function reconcile(Model $model, bool $present, int $now): array
    {
        $verdict = $this->verdict($model, $present);
        $health = $this->healthRepository->findOrCreate((int) $model->getId());
        $health->setState($verdict->state)
            ->setSource(ModelHealth::SOURCE_LISTING)
            ->setKind($verdict->kind?->value)
            ->setMessage('' === $verdict->message ? null : $verdict->message)
            ->setLastCheck($now)
            ->setUpdated($now);
        if ($present) {
            $health->setLastSuccess($now);
        } else {
            $health->setLastFailure($now);
        }

        return $this->autoDisabler->apply($verdict, $model, $health, $now);
    }

    private function verdict(Model $model, bool $present): ModelHealthVerdict
    {
        if ($present) {
            return new ModelHealthVerdict(
                modelId: (int) $model->getId(),
                service: $model->getService(),
                modelName: $model->getName(),
                providerId: $model->getProviderId(),
                tag: $model->getTag(),
                state: ModelHealthState::Online,
                kind: null,
                message: '',
                source: ModelHealth::SOURCE_LISTING,
            );
        }

        return new ModelHealthVerdict(
            modelId: (int) $model->getId(),
            service: $model->getService(),
            modelName: $model->getName(),
            providerId: $model->getProviderId(),
            tag: $model->getTag(),
            state: ModelHealthState::Offline,
            kind: FailureKind::Permanent,
            message: self::MISSING_MESSAGE,
            source: ModelHealth::SOURCE_LISTING,
            // The source itself answered and did not list this model — the one
            // case where absence is safe to act on (unlike a partial catalog).
            safeToDisable: true,
        );
    }

    private function discoverSafely(string $source): ?DiscoveryResult
    {
        try {
            return $this->discovery->discover($source);
        } catch (UnknownImportSourceException $e) {
            $this->logger->info('Imported model source no longer resolvable, skipping', [
                'source' => $source,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array<string, list<Model>>
     */
    private function importedModelsBySource(): array
    {
        $bySource = [];
        foreach ($this->models->findBy([]) as $model) {
            $source = $model->getJson()['meta']['import']['source'] ?? null;
            if (is_string($source) && '' !== $source) {
                $bySource[$source][] = $model;
            }
        }

        return $bySource;
    }
}
