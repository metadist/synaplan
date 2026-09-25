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
 * `--notify` run with Discord disabled) marks it announced.
 *
 * Pending ids (seen after baseline, not known via
 * {@see ModelDiscoveryIdNormalizer::isKnown()}, not ignored / class-silenced)
 * split into `newPending` (not yet announced) and `openPending` (announced
 * earlier). Discord posts new ids once, summarises open ones, and reminds
 * fully every Monday. Provider listing failures follow the same once + Monday
 * cadence via `failingSince` / `failureAnnounced`.
 *
 * @phpstan-type ProviderState array{
 *     baselineRecorded: bool,
 *     baselineAnnounced: bool,
 *     baselineIds: list<string>,
 *     seen: array<string, string>,
 *     announced: array<string, string>,
 *     failingSince: string|null,
 *     failureAnnounced: bool
 * }
 */
final readonly class ModelDiscoveryService
{
    /**
     * @param array<string, array{reason: string, decidedOn: string}>|null                                                     $ignoreEntries
     *                                                                                                                                        test seam; production leaves null to use ModelDiscoveryIgnoreList
     * @param list<array{provider: string, match: 'prefix'|'contains', value: string, reason: string, decidedOn: string}>|null $classRules
     *                                                                                                                                        test seam; production leaves null to use ModelDiscoveryIgnoreList::classRules()
     */
    public function __construct(
        private ProviderModelInventoryInterface $inventory,
        private ModelRepository $modelRepository,
        private ModelDiscoveryStateStore $stateStore,
        private ClockInterface $clock,
        private bool $enabled,
        private ?array $ignoreEntries = null,
        private ?array $classRules = null,
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
        $isMonday = '1' === $this->clock->now()->format('N');
        $knownByProvider = $this->indexKnownModels();
        $state = $this->stateStore->loadProviders();

        $providers = [];
        $pending = [];
        $newPending = [];
        $openPending = [];
        $failedProviders = [];
        $silencedByClass = [];
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
                'silencedByClass' => 0,
            ];

            if (ProviderModelListing::STATUS_NOT_CONFIGURED === $listing->status
                || ProviderModelListing::STATUS_NO_LISTING_ENDPOINT === $listing->status) {
                continue;
            }

            if (ProviderModelListing::STATUS_UNREACHABLE === $listing->status) {
                $providerState = $state[$provider] ?? $this->emptyProviderState();
                $wasFailing = null !== $providerState['failingSince'];
                if (!$wasFailing) {
                    $providerState['failingSince'] = $today;
                    $providerState['failureAnnounced'] = false;
                }
                $state[$provider] = $providerState;

                $isNew = !$providerState['failureAnnounced'];
                $failedProviders[] = [
                    'provider' => $provider,
                    'detail' => $listing->detail ?? 'unreachable',
                    'failingSince' => $providerState['failingSince'] ?? $today,
                    'isNew' => $isNew,
                ];
                continue;
            }

            if (ProviderModelListing::STATUS_OK !== $listing->status) {
                continue;
            }

            $listedIds = $listing->modelIds;
            $okListedByProvider[$provider] = $listedIds;

            $providerState = $state[$provider] ?? $this->emptyProviderState();
            if (null !== $providerState['failingSince'] || $providerState['failureAnnounced']) {
                $providerState['failingSince'] = null;
                $providerState['failureAnnounced'] = false;
            }

            if (!$providerState['baselineRecorded']) {
                $state[$provider] = $this->recordBaseline($listedIds, $today);

                continue;
            }

            $updated = $this->advanceSeen($providerState, $listedIds, $today);
            $collected = $this->collectPending(
                $provider,
                $listedIds,
                $updated,
                $knownByProvider[$provider] ?? [],
                $today,
            );
            $updated['announced'] = $this->pruneAnnounced($updated['announced'], $collected['pending']);
            $state[$provider] = $updated;

            $pending = array_merge($pending, $collected['pending']);
            $newPending = array_merge($newPending, $collected['newPending']);
            $openPending = array_merge($openPending, $collected['openPending']);

            $last = count($providers) - 1;
            $providers[$last]['pendingCount'] = count($collected['pending']);
            $providers[$last]['silencedByClass'] = $collected['silencedByClass'];
            if ($collected['silencedByClass'] > 0) {
                $silencedByClass[$provider] = $collected['silencedByClass'];
            }
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

        $sortPending = static fn (array $a, array $b): int => [$a['provider'], $a['id']] <=> [$b['provider'], $b['id']];
        usort($pending, $sortPending);
        usort($newPending, $sortPending);
        usort($openPending, $sortPending);

        $hasNewFailure = false;
        $hasOpenFailure = false;
        foreach ($failedProviders as $fail) {
            if ($fail['isNew']) {
                $hasNewFailure = true;
            } else {
                $hasOpenFailure = true;
            }
        }

        $isMondayReminder = $isMonday && ([] !== $openPending || $hasOpenFailure);
        $shouldNotify = [] !== $newPending
            || $hasNewFailure
            || [] !== $baselinesRecorded
            || $isMondayReminder;

        return new ModelDiscoveryReport(
            providers: $providers,
            pending: $pending,
            newPending: $newPending,
            openPending: $openPending,
            failedProviders: $failedProviders,
            baselinesRecorded: $baselinesRecorded,
            obsoleteIgnores: $obsoleteIgnores,
            silencedByClass: $silencedByClass,
            shouldNotify: $shouldNotify,
            isMondayReminder: $isMondayReminder,
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
     * After a successful Discord post (or `--notify` with Discord disabled),
     * mark reported newPending ids and reported failures as announced.
     */
    public function markDiscoveriesAnnounced(ModelDiscoveryReport $report): void
    {
        $today = $this->clock->now()->format('Y-m-d');
        $state = $this->stateStore->loadProviders();
        $changed = false;

        foreach ($report->newPending as $item) {
            $provider = $item['provider'];
            if (!isset($state[$provider])) {
                $state[$provider] = $this->emptyProviderState();
            }
            if (!isset($state[$provider]['announced'][$item['id']])) {
                $state[$provider]['announced'][$item['id']] = $today;
                $changed = true;
            }
        }

        foreach ($report->failedProviders as $fail) {
            $include = $fail['isNew'] || $report->isMondayReminder;
            if (!$include) {
                continue;
            }
            $provider = $fail['provider'];
            if (!isset($state[$provider])) {
                continue;
            }
            if ($state[$provider]['failureAnnounced']) {
                continue;
            }
            $state[$provider]['failureAnnounced'] = true;
            $changed = true;
        }

        foreach ($report->baselinesRecorded as $event) {
            $provider = $event['provider'];
            if (!isset($state[$provider]) || $state[$provider]['baselineAnnounced']) {
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
     * @return ProviderState
     */
    private function emptyProviderState(): array
    {
        return [
            'baselineRecorded' => false,
            'baselineAnnounced' => false,
            'baselineIds' => [],
            'seen' => [],
            'announced' => [],
            'failingSince' => null,
            'failureAnnounced' => false,
        ];
    }

    /**
     * @param list<string> $listedIds
     *
     * @return ProviderState
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
            'announced' => [],
            'failingSince' => null,
            'failureAnnounced' => false,
        ];
    }

    /**
     * @param ProviderState $state
     * @param list<string>  $listedIds
     *
     * @return ProviderState
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
            'announced' => $state['announced'],
            'failingSince' => null,
            'failureAnnounced' => false,
        ];
    }

    /**
     * @param list<string>        $listedIds
     * @param array<string, true> $knownKeys
     * @param ProviderState       $state
     *
     * @return array{
     *     pending: list<array{provider: string, id: string, firstSeen: string, daysPending: int, label: string}>,
     *     newPending: list<array{provider: string, id: string, firstSeen: string, daysPending: int, label: string}>,
     *     openPending: list<array{provider: string, id: string, firstSeen: string, daysPending: int, label: string}>,
     *     silencedByClass: int
     * }
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
        $newPending = [];
        $openPending = [];
        $silencedByClass = 0;

        foreach ($listedIds as $id) {
            if (isset($baselineSet[$id])) {
                continue;
            }
            if ($this->matchesClassRule($provider, $id)) {
                ++$silencedByClass;
                continue;
            }
            if ($this->isExactIgnored($provider, $id)) {
                continue;
            }
            if (ModelDiscoveryIdNormalizer::isKnown($id, $knownKeys)) {
                continue;
            }

            $firstSeen = $state['seen'][$id] ?? $today;
            $classified = ModelFamilyClassifier::classify($id, $knownKeys, $provider);
            $item = [
                'provider' => $provider,
                'id' => $id,
                'firstSeen' => $firstSeen,
                'daysPending' => $this->daysBetween($firstSeen, $today),
                'label' => $classified['label'],
            ];
            $pending[] = $item;
            if (isset($state['announced'][$id])) {
                $openPending[] = $item;
            } else {
                $newPending[] = $item;
            }
        }

        return [
            'pending' => $pending,
            'newPending' => $newPending,
            'openPending' => $openPending,
            'silencedByClass' => $silencedByClass,
        ];
    }

    /**
     * @param array<string, string>                                                                         $announced
     * @param list<array{provider: string, id: string, firstSeen: string, daysPending: int, label: string}> $pending
     *
     * @return array<string, string>
     */
    private function pruneAnnounced(array $announced, array $pending): array
    {
        $pendingIds = [];
        foreach ($pending as $item) {
            $pendingIds[$item['id']] = true;
        }

        $kept = [];
        foreach ($announced as $id => $date) {
            if (isset($pendingIds[$id])) {
                $kept[$id] = $date;
            }
        }

        return $kept;
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

    private function isExactIgnored(string $provider, string $modelId): bool
    {
        return isset($this->ignoreEntries()[ModelDiscoveryIgnoreList::key($provider, $modelId)]);
    }

    private function matchesClassRule(string $provider, string $modelId): bool
    {
        if (null !== $this->classRules) {
            $providerKey = strtolower(trim($provider));
            $id = strtolower(trim($modelId));
            foreach ($this->classRules as $rule) {
                if ($rule['provider'] !== $providerKey) {
                    continue;
                }
                $hits = match ($rule['match']) {
                    'prefix' => str_starts_with($id, $rule['value']),
                    'contains' => str_contains($id, $rule['value']),
                };
                if ($hits) {
                    return true;
                }
            }

            return false;
        }

        return null !== ModelDiscoveryIgnoreList::matchingClassRule($provider, $modelId);
    }
}
