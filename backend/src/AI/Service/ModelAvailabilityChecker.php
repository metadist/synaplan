<?php

declare(strict_types=1);

namespace App\AI\Service;

use App\AI\Credential\ProviderDefaultsService;
use App\Model\ModelCatalog;
use App\Repository\ModelRepository;

/**
 * Compares the models we offer against the models providers actually serve.
 *
 * Two scopes are reported independently, and they are genuinely different:
 *
 *   - {@see SCOPE_DATABASE} — what does THIS install still offer? Answered
 *     against active `BMODELS` rows, because `ModelSeeder` never deactivates
 *     anything: a row the catalog dropped long ago can still be selectable
 *     here, and only the database knows that.
 *   - {@see SCOPE_CATALOG} — what do we still ship to NEW installs? Answered
 *     against {@see ModelCatalog}. An operator who already cleaned up their own
 *     database would see nothing wrong while every fresh install keeps getting
 *     the dead model.
 *
 * Detection runs in two stages. The provider's bulk listing is a cheap
 * pre-filter, then every model missing from it is confirmed individually via
 * {@see ProviderModelInventoryInterface::probe()}. The second stage is what makes the
 * result trustworthy — listings omit live models (Gemini keeps Imagen out of
 * `models.list`) and a listing-only verdict produces both false alarms and
 * blind spots. Only a model the provider itself reports as unknown is confirmed.
 *
 * Findings stay advisory and are never applied automatically: retirement
 * touches every user of an install and remains a reviewed migration.
 */
final readonly class ModelAvailabilityChecker
{
    public const SCOPE_DATABASE = 'database';
    public const SCOPE_CATALOG = 'catalog';

    /**
     * Ceiling on per-model probes per provider. Reached only when a listing is
     * technically valid but wildly incomplete; without the cap, one malformed
     * response would turn into hundreds of requests. Models past the ceiling
     * are reported unconfirmed rather than silently dropped.
     */
    private const MAX_PROBES_PER_PROVIDER = 25;

    public function __construct(
        private ProviderModelInventoryInterface $inventory,
        private ModelRepository $modelRepository,
        private ProviderDefaultsService $providerDefaults,
    ) {
    }

    /**
     * @return array{
     *     providers: array<string, array{status: string, detail: string|null, servedCount: int, matchedCount: int, offeredCount: int}>,
     *     findings: list<array{
     *         provider: string,
     *         providerId: string,
     *         name: string,
     *         tag: string,
     *         bids: list<int>,
     *         scopes: list<string>,
     *         recommended: bool,
     *         confirmed: bool,
     *     }>,
     * }
     */
    public function run(): array
    {
        $offered = $this->offeredModels();
        $listings = [];
        foreach (array_keys($offered) as $provider) {
            $listings[$provider] = $this->inventory->fetch($provider);
        }

        $recommendedBids = $this->recommendedBids();
        $findings = [];
        $providers = [];

        foreach ($offered as $provider => $models) {
            $listing = $listings[$provider];
            $matched = 0;
            $probes = 0;

            foreach ($models as $model) {
                if (!$listing->isConclusive()) {
                    continue;
                }
                if ($listing->serves($model['providerId'])) {
                    ++$matched;
                    continue;
                }

                $verdict = $probes < self::MAX_PROBES_PER_PROVIDER
                    ? $this->inventory->probe($provider, $model['providerId'])
                    : ModelProbeResult::Inconclusive;
                ++$probes;

                if (ModelProbeResult::Alive === $verdict) {
                    ++$matched;
                    continue;
                }

                $key = $provider.'|'.strtolower($model['providerId']);
                $findings[$key] ??= [
                    'provider' => $provider,
                    'providerId' => $model['providerId'],
                    'name' => $model['name'],
                    'tag' => $model['tag'],
                    'bids' => [],
                    'scopes' => [],
                    'recommended' => false,
                    'confirmed' => ModelProbeResult::Gone === $verdict,
                ];
                foreach ($model['bids'] as $bid) {
                    if (!in_array($bid, $findings[$key]['bids'], true)) {
                        $findings[$key]['bids'][] = $bid;
                    }
                    $findings[$key]['recommended'] = $findings[$key]['recommended'] || ($recommendedBids[$bid] ?? false);
                }
                foreach ($model['scopes'] as $scope) {
                    if (!in_array($scope, $findings[$key]['scopes'], true)) {
                        $findings[$key]['scopes'][] = $scope;
                    }
                }
            }

            $providers[$provider] = [
                'status' => $listing->status,
                'detail' => $listing->detail,
                'servedCount' => count($listing->modelIds),
                'matchedCount' => $matched,
                'offeredCount' => count($models),
            ];
        }

        ksort($providers);
        $findings = array_values($findings);
        usort($findings, static function (array $a, array $b): int {
            return [$b['confirmed'], $b['recommended'], $a['provider']]
                <=> [$a['confirmed'], $a['recommended'], $b['provider']];
        });

        return ['providers' => $providers, 'findings' => $findings];
    }

    /**
     * Everything we offer, per provider, merged across both scopes.
     *
     * @return array<string, array<string, array{providerId: string, name: string, tag: string, bids: list<int>, scopes: list<string>}>>
     */
    private function offeredModels(): array
    {
        $offered = [];

        foreach ($this->modelRepository->findAllActive() as $model) {
            $this->addOffered($offered, $model->getService(), $model->getProviderId(), $model->getName(), $model->getTag(), $model->getId(), self::SCOPE_DATABASE);
        }

        foreach (ModelCatalog::all() as $row) {
            if (1 !== (int) ($row['active'] ?? 0)) {
                continue;
            }
            $this->addOffered($offered, (string) $row['service'], (string) $row['providerId'], (string) $row['name'], (string) $row['tag'], (int) $row['id'], self::SCOPE_CATALOG);
        }

        return $offered;
    }

    /**
     * @param array<string, array<string, array{providerId: string, name: string, tag: string, bids: list<int>, scopes: list<string>}>> $offered
     */
    private function addOffered(array &$offered, string $service, string $providerId, string $name, string $tag, ?int $bid, string $scope): void
    {
        $provider = ModelCatalog::normalizeProvider($service);
        $key = strtolower($providerId).'|'.strtolower($tag);

        $offered[$provider][$key] ??= [
            'providerId' => $providerId,
            'name' => $name,
            'tag' => strtolower($tag),
            'bids' => [],
            'scopes' => [],
        ];

        if (null !== $bid && !in_array($bid, $offered[$provider][$key]['bids'], true)) {
            $offered[$provider][$key]['bids'][] = $bid;
        }
        if (!in_array($scope, $offered[$provider][$key]['scopes'], true)) {
            $offered[$provider][$key]['scopes'][] = $scope;
        }
    }

    /**
     * BIDs a provider recommends as a default binding. A dead model here is the
     * urgent case: `app:provider:apply-defaults --auto` writes these unattended
     * at container start, so nobody has to choose the model to be hit by it.
     *
     * @return array<int, bool>
     */
    private function recommendedBids(): array
    {
        $bids = [];
        foreach (ProviderDefaultsService::PREFERENCE_ORDER as $provider) {
            if (!ProviderDefaultsService::supports($provider)) {
                continue;
            }

            try {
                foreach ($this->providerDefaults->getRecommendedDefaults($provider) as $bid) {
                    $bids[$bid] = true;
                }
            } catch (\Throwable) {
                // A mapping that no longer resolves is a build inconsistency
                // locked by ProviderDefaultsServiceTest; it must not break the
                // report.
                continue;
            }
        }

        return $bids;
    }
}
