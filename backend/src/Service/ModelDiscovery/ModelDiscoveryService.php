<?php

declare(strict_types=1);

namespace App\Service\ModelDiscovery;

use App\AI\Credential\ProviderKeyCatalog;
use App\AI\Service\ProviderModelInventoryInterface;
use App\AI\Service\ProviderModelListing;
use App\Model\ModelCatalog;
use App\Model\ModelDiscoveryIgnoreList;
use App\Repository\ModelRepository;
use Psr\Clock\ClockInterface;

/**
 * Detects newly listed upstream models that this install does not yet offer.
 *
 * Source of truth is each provider's own model list via
 * {@see ProviderModelInventoryInterface::fetch()} — the same inventory the
 * availability check uses. Nothing is written to BMODELS or ModelCatalog;
 * findings are advisory until a human adds a catalog row or an ignore entry.
 *
 * Per-provider silent baseline: the first successful listing records every
 * current id and reports none as pending. The baseline stays in
 * {@see ModelDiscoveryReport::$baselinesRecorded} until Discord (or a
 * `--notify` run with Discord disabled) marks it announced. Pending ids (seen
 * after baseline, not known via {@see ModelDiscoveryIdNormalizer::isKnown()},
 * not ignored) are reported every run until resolved or unlisted.
 */
final readonly class ModelDiscoveryService
{
    /**
     * @param array<string, array{reason: string, decidedOn: string}>|null $ignoreEntries
     *                                                                                    test seam; production leaves null to use ModelDiscoveryIgnoreList
     */
    public function __construct(
        private ProviderModelInventoryInterface $inventory,
        private ModelRepository $modelRepository,
        private ModelDiscoveryStateStore $stateStore,
        private ClockInterface $clock,
        private bool $enabled,
        private ?array $ignoreEntries = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function run(): ModelDiscoveryReport
    {
        if (!$this->enabled) {
            throw new \LogicException('Model discovery is disabled (MODEL_DISCOVERY_ENABLED=false).');
        }

        $today = $this->clock->now()->format('Y-m-d');
        $knownByProvider = $this->indexKnownModels();
        $state = $this->stateStore->loadProviders();

        $providers = [];
        $pending = [];
        $failedProviders = [];
        /** @var array<string, list<string>> */
        $okListedByProvider = [];

        foreach (ProviderKeyCatalog::providerNames() as $provider) {
            $listing = $this->inventory->fetch($provider);

            $providers[] = [
                'provider' => $provider,
                'status' => $listing->status,
                'detail' => $listing->detail,
                'listedCount' => count($listing->modelIds),
                'pendingCount' => 0,
            ];

            if (ProviderModelListing::STATUS_NOT_CONFIGURED === $listing->status
                || ProviderModelListing::STATUS_NO_LISTING_ENDPOINT === $listing->status) {
                continue;
            }

            if (ProviderModelListing::STATUS_UNREACHABLE === $listing->status) {
                $failedProviders[] = [
                    'provider' => $provider,
                    'detail' => $listing->detail ?? 'unreachable',
                ];
                continue;
            }

            if (ProviderModelListing::STATUS_OK !== $listing->status) {
                continue;
            }

            $listedIds = $listing->modelIds;
            $okListedByProvider[$provider] = $listedIds;

            $providerState = $state[$provider] ?? [
                'baselineRecorded' => false,
                'baselineAnnounced' => false,
                'baselineIds' => [],
                'seen' => [],
            ];

            if (!$providerState['baselineRecorded']) {
                $state[$provider] = $this->recordBaseline($listedIds, $today);
                continue;
            }

            $updated = $this->advanceSeen($providerState, $listedIds, $today);
            $state[$provider] = $updated;

            $providerPending = $this->collectPending(
                $provider,
                $listedIds,
                $updated,
                $knownByProvider[$provider] ?? [],
                $today,
            );
            $pending = array_merge($pending, $providerPending);

            $last = count($providers) - 1;
            $providers[$last]['pendingCount'] = count($providerPending);
        }

        $this->stateStore->saveProviders($state);

        $baselinesRecorded = [];
        foreach ($state as $provider => $providerState) {
            if ($providerState['baselineRecorded'] && !$providerState['baselineAnnounced']) {
                $baselinesRecorded[] = [
                    'provider' => $provider,
                    'idCount' => count($providerState['baselineIds']),
                ];
            }
        }

        $obsoleteIgnores = $this->findObsoleteIgnores($okListedByProvider, $knownByProvider);

        usort($pending, static fn (array $a, array $b): int => [$a['provider'], $a['id']] <=> [$b['provider'], $b['id']]);

        return new ModelDiscoveryReport(
            providers: $providers,
            pending: $pending,
            failedProviders: $failedProviders,
            baselinesRecorded: $baselinesRecorded,
            obsoleteIgnores: $obsoleteIgnores,
            shouldNotify: [] !== $pending || [] !== $failedProviders || [] !== $baselinesRecorded,
        );
    }

    /**
     * Claim the Discord post for today. Returns false when another node already posted.
     */
    public function claimNotifyDay(): bool
    {
        return $this->stateStore->claimNotifyDay($this->clock->now()->format('Y-m-d'));
    }

    /**
     * Release today's Discord claim after a failed post so the next run can retry.
     */
    public function releaseNotifyDay(): void
    {
        $this->stateStore->releaseNotifyDay($this->clock->now()->format('Y-m-d'));
    }

    /**
     * Mark the given providers' baselines as announced (successful Discord post,
     * or `--notify` with Discord disabled so the console does not repeat forever).
     *
     * @param list<string> $providers
     */
    public function markBaselinesAnnounced(array $providers): void
    {
        if ([] === $providers) {
            return;
        }

        $state = $this->stateStore->loadProviders();
        $changed = false;
        foreach ($providers as $provider) {
            if (!isset($state[$provider])) {
                continue;
            }
            if ($state[$provider]['baselineAnnounced']) {
                continue;
            }
            $state[$provider]['baselineAnnounced'] = true;
            $changed = true;
        }

        if ($changed) {
            $this->stateStore->saveProviders($state);
        }
    }

    /**
     * @param list<string> $listedIds
     *
     * @return array{
     *     baselineRecorded: bool,
     *     baselineAnnounced: bool,
     *     baselineIds: list<string>,
     *     seen: array<string, string>
     * }
     */
    private function recordBaseline(array $listedIds, string $today): array
    {
        $seen = [];
        foreach ($listedIds as $id) {
            $seen[$id] = $today;
        }

        return [
            'baselineRecorded' => true,
            'baselineAnnounced' => false,
            'baselineIds' => $listedIds,
            'seen' => $seen,
        ];
    }

    /**
     * @param array{
     *     baselineRecorded: bool,
     *     baselineAnnounced: bool,
     *     baselineIds: list<string>,
     *     seen: array<string, string>
     * }               $state
     * @param list<string> $listedIds
     *
     * @return array{
     *     baselineRecorded: bool,
     *     baselineAnnounced: bool,
     *     baselineIds: list<string>,
     *     seen: array<string, string>
     * }
     */
    private function advanceSeen(array $state, array $listedIds, string $today): array
    {
        $listedSet = array_fill_keys($listedIds, true);
        $seen = [];
        foreach ($state['seen'] as $id => $firstSeen) {
            if (isset($listedSet[$id])) {
                $seen[$id] = $firstSeen;
            }
        }
        foreach ($listedIds as $id) {
            if (!isset($seen[$id])) {
                $seen[$id] = $today;
            }
        }

        return [
            'baselineRecorded' => true,
            'baselineAnnounced' => $state['baselineAnnounced'],
            'baselineIds' => $state['baselineIds'],
            'seen' => $seen,
        ];
    }

    /**
     * @param list<string>        $listedIds
     * @param array<string, true> $knownKeys
     * @param array{
     *     baselineRecorded: bool,
     *     baselineAnnounced: bool,
     *     baselineIds: list<string>,
     *     seen: array<string, string>
     * }                          $state
     *
     * @return list<array{provider: string, id: string, firstSeen: string, daysPending: int}>
     */
    private function collectPending(
        string $provider,
        array $listedIds,
        array $state,
        array $knownKeys,
        string $today,
    ): array {
        $baselineSet = array_fill_keys($state['baselineIds'], true);
        $pending = [];

        foreach ($listedIds as $id) {
            if (isset($baselineSet[$id])) {
                continue;
            }
            if ($this->isIgnored($provider, $id)) {
                continue;
            }
            if (ModelDiscoveryIdNormalizer::isKnown($id, $knownKeys)) {
                continue;
            }

            $firstSeen = $state['seen'][$id] ?? $today;
            $pending[] = [
                'provider' => $provider,
                'id' => $id,
                'firstSeen' => $firstSeen,
                'daysPending' => $this->daysBetween($firstSeen, $today),
            ];
        }

        return $pending;
    }

    /**
     * @param array<string, list<string>>        $okListedByProvider
     * @param array<string, array<string, true>> $knownByProvider
     *
     * @return list<array{key: string, reason: string, decidedOn: string, why: 'gone_upstream'|'now_in_bmodels'}>
     */
    private function findObsoleteIgnores(array $okListedByProvider, array $knownByProvider): array
    {
        $obsolete = [];
        foreach ($this->ignoreEntries() as $key => $entry) {
            $colon = strpos($key, ':');
            if (false === $colon) {
                continue;
            }
            $provider = substr($key, 0, $colon);
            $id = substr($key, $colon + 1);

            if (ModelDiscoveryIdNormalizer::isKnown($id, $knownByProvider[$provider] ?? [])) {
                $obsolete[] = [
                    'key' => $key,
                    'reason' => $entry['reason'],
                    'decidedOn' => $entry['decidedOn'],
                    'why' => 'now_in_bmodels',
                ];
                continue;
            }

            if (!isset($okListedByProvider[$provider])) {
                continue;
            }

            $listedSet = array_fill_keys($okListedByProvider[$provider], true);
            if (!isset($listedSet[$id])) {
                $obsolete[] = [
                    'key' => $key,
                    'reason' => $entry['reason'],
                    'decidedOn' => $entry['decidedOn'],
                    'why' => 'gone_upstream',
                ];
            }
        }

        return $obsolete;
    }

    private function daysBetween(string $fromYmd, string $toYmd): int
    {
        $from = \DateTimeImmutable::createFromFormat('Y-m-d', $fromYmd);
        $to = \DateTimeImmutable::createFromFormat('Y-m-d', $toYmd);
        if (false === $from || false === $to) {
            return 0;
        }

        return max(0, (int) $from->diff($to)->days);
    }

    /**
     * Every BMODELS row for this install, including inactive / unselectable /
     * retired — a deliberate retirement is a known decision.
     *
     * @return array<string, array<string, true>> provider => normalised key => true
     */
    private function indexKnownModels(): array
    {
        $known = [];
        foreach ($this->modelRepository->findAllForDiscovery() as $model) {
            $provider = ModelCatalog::normalizeProvider($model->getService());
            $ids = [$model->getProviderId()];
            $paramsModel = $model->getJson()['params']['model'] ?? null;
            if (is_string($paramsModel) && '' !== $paramsModel) {
                $ids[] = $paramsModel;
            }
            foreach ($ids as $id) {
                $known[$provider][ModelDiscoveryIdNormalizer::normalize($id)] = true;
            }
        }

        return $known;
    }

    /**
     * @return array<string, array{reason: string, decidedOn: string}>
     */
    private function ignoreEntries(): array
    {
        return $this->ignoreEntries ?? ModelDiscoveryIgnoreList::entries();
    }

    private function isIgnored(string $provider, string $modelId): bool
    {
        return isset($this->ignoreEntries()[ModelDiscoveryIgnoreList::key($provider, $modelId)]);
    }
}
