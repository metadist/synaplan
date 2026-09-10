<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Model;
use App\Entity\ModelPriceHistory;
use App\Model\ModelCatalog;
use App\Repository\ModelPriceHistoryRepository;
use App\Repository\ModelRepository;
use App\Service\CostCalculationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:sync-model-prices',
    description: 'Sync model prices from LiteLLM pricing database',
)]
class SyncModelPricesCommand extends Command
{
    /**
     * Exit code returned when --fail-on-drift is set and price drift was
     * detected — either a per-token price or a same-mode non-per-token price
     * (a headline rate or a single resolution tier) that differs from LiteLLM.
     * Distinct from Command::FAILURE (1, generic error) so CI can tell
     * "provider prices moved" apart from "the command itself broke".
     *
     * A drift is a signal to verify, not a value to copy: LiteLLM has carried
     * wrong numbers for weeks (Veo 3.1 Fast, Jina rerank). Deviations that were
     * checked against the official page and found to be LiteLLM's error are
     * recorded in ModelCatalog::LITELLM_DEVIATIONS and no longer count.
     */
    private const EXIT_DRIFT_DETECTED = 2;

    /**
     * Pricing mode reported for LiteLLM `rerank` entries that bill per request
     * (`input_cost_per_query`, Cohere). Billing has no such mode, so a catalog
     * row can never carry it and the entry always lands in the structural
     * mode-mismatch bucket — visible, never counted as drift.
     */
    private const MODE_PER_REQUEST = 'per_request';

    private const LITELLM_URL = 'https://raw.githubusercontent.com/BerriAI/litellm/main/model_prices_and_context_window.json';

    /**
     * LiteLLM key prefix for a per-second rate that applies to one resolution
     * tier only, e.g. `output_cost_per_second_1080p` / `_4k`. The suffix is the
     * lowercased tier name, which is how it maps onto our own
     * `json.resolution_prices` keys ('720p', '1080p', '4K').
     */
    private const RESOLUTION_TIER_PREFIX = 'output_cost_per_second_';

    private const LOCAL_PROVIDERS = ['ollama', 'triton', 'test', 'piper', 'thehive'];

    /**
     * Maps DB service names (lowercase) to LiteLLM key prefixes to try.
     *
     * @var array<string, list<string>>
     */
    private const PREFIX_MAP = [
        'openai' => ['openai'],
        'anthropic' => ['anthropic'],
        'google' => ['gemini', 'google'],
        'groq' => ['groq'],
        'huggingface' => ['huggingface'],
    ];

    /**
     * @var array<string, array{litellm_in: float, litellm_out: float, source: string, verifiedOn: string, reason: string}>
     */
    private readonly array $litellmDeviations;

    /**
     * $litellmDeviations replaces ModelCatalog::LITELLM_DEVIATIONS; only tests
     * pass it, so they never depend on the live registry.
     *
     * @param array<string, array{litellm_in: float, litellm_out: float, source: string, verifiedOn: string, reason: string}>|null $litellmDeviations
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private ModelRepository $modelRepository,
        private ModelPriceHistoryRepository $priceHistoryRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        ?array $litellmDeviations = null,
    ) {
        parent::__construct();
        $this->litellmDeviations = $litellmDeviations ?? ModelCatalog::litellmDeviations();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show changes without applying')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Override admin-set prices')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Only sync a specific provider')
            ->addOption('fail-on-drift', null, InputOption::VALUE_NONE, 'Exit with code 2 if any price drift is detected — per-token, or same-mode non-per-token including individual resolution tiers (for the scheduled CI drift check; use with --dry-run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $failOnDrift = (bool) $input->getOption('fail-on-drift');
        $providerFilter = $input->getOption('provider');

        $io->title('Syncing model prices from LiteLLM');

        try {
            $litellmData = $this->fetchLiteLLMPrices();
        } catch (\Exception $e) {
            $io->error('Failed to fetch LiteLLM prices: '.$e->getMessage());
            $this->logger->error('Price sync failed: could not fetch LiteLLM data', ['error' => $e->getMessage()]);

            return Command::FAILURE;
        }

        $io->info(sprintf('Loaded %d models from LiteLLM', count($litellmData)));

        $dbModels = $this->modelRepository->findAll();
        $updated = 0;
        $unchanged = 0;
        $skipped = 0;
        $nullPriceSkipped = 0;
        $modeMismatch = 0;
        $nonTokenDrift = 0;
        $knownDeviation = 0;
        $notMatched = 0;
        $unmatchedList = [];
        $nullPriceList = [];
        $modeMismatchList = [];
        $nonTokenDriftList = [];
        $knownDeviationList = [];
        $obsoleteDeviationList = [];

        foreach ($dbModels as $model) {
            $service = $model->getService();

            if ($providerFilter && strtolower($service) !== strtolower($providerFilter)) {
                continue;
            }

            if (in_array(strtolower($service), self::LOCAL_PROVIDERS, true)) {
                continue;
            }

            $litellmKey = $this->findLiteLLMKey($model, $litellmData);
            if (!$litellmKey) {
                ++$notMatched;
                $unmatchedList[] = sprintf('%s/%s (ID %d)', $service, $model->getProviderId(), $model->getId());
                continue;
            }

            $litellmModel = $litellmData[$litellmKey];
            $pricing = $this->extractPricing($litellmModel);

            $currentMode = $model->getJson()['pricing_mode'] ?? 'per_token';
            $newMode = $pricing['pricing_mode'];

            // Case 1 — mode mismatch (reclassification). LiteLLM derives its own
            // billing mode, which can structurally disagree with a deliberately-set
            // catalog mode (e.g. gpt-image is per_image here but per_token upstream
            // because LiteLLM counts the prompt tokens). The two numbers measure
            // different things, so they can never be compared and applying the change
            // would corrupt a correct price. Reported for human awareness but NOT
            // counted as drift — the mismatch is permanent, so failing CI on it would
            // just go red forever. Never written, not overridable by --force.
            if ($currentMode !== $newMode) {
                ++$modeMismatch;
                $modeMismatchList[] = sprintf(
                    '%s/%s (ID %d) — catalog=%s, litellm=%s',
                    $service,
                    $model->getProviderId(),
                    $model->getId(),
                    $currentMode,
                    $newMode,
                );
                continue;
            }

            // Known deviation — a human already verified this row against the
            // official page and found LiteLLM wrong (ModelCatalog::LITELLM_DEVIATIONS).
            // The entry pins the LiteLLM value we disagree with, so it silences
            // exactly that value and nothing else: LiteLLM moving to our rate makes
            // the entry obsolete (reported so it gets deleted), LiteLLM moving
            // anywhere else is a fresh drift and falls through to the normal check.
            $deviation = $this->litellmDeviations[ModelCatalog::litellmDeviationKey($service, $model->getProviderId())] ?? null;
            if (null !== $deviation) {
                $verdict = $this->deviationVerdict($deviation, $pricing, $this->pricesInLiteLLMUnits($model, $currentMode));

                if ('pinned' === $verdict) {
                    ++$knownDeviation;
                    $knownDeviationList[] = sprintf(
                        '%s/%s (ID %d) — catalog keeps in=%.6f out=%.6f, LiteLLM says in=%.6f out=%.6f | verified %s against %s | %s',
                        $service,
                        $model->getProviderId(),
                        $model->getId(),
                        $model->getPriceIn(),
                        $model->getPriceOut(),
                        $pricing['price_in'],
                        $pricing['price_out'],
                        $deviation['verifiedOn'],
                        $deviation['source'],
                        $deviation['reason'],
                    );
                    continue;
                }

                if ('obsolete' === $verdict) {
                    ++$unchanged;
                    $obsoleteDeviationList[] = sprintf(
                        '%s/%s (ID %d) — LiteLLM now agrees with the catalog (in=%.6f out=%.6f); delete the LITELLM_DEVIATIONS entry',
                        $service,
                        $model->getProviderId(),
                        $model->getId(),
                        $pricing['price_in'],
                        $pricing['price_out'],
                    );
                    continue;
                }
            }

            // Case 2 — same non-per-token mode on both sides (per_second, per_image,
            // per_character). The prices ARE comparable once normalised to a single
            // unit, so we DETECT drift here (this is what makes whisper/tts/veo/imagen
            // checkable at all, #1318). Resolution-tiered rows are compared tier by
            // tier, because a provider can reprice 1080p or 4K while the headline
            // rate stays put. We do not auto-write: these catalog rows are
            // hand-authored with unit conventions and tier JSON the flat sync can't
            // reproduce. A human updates ModelCatalog.php after verifying the source.
            if ('per_token' !== $currentMode) {
                $tierDrift = $this->driftedResolutionTiers($model, $pricing);

                if ($this->nonTokenPriceDrifted($model, $pricing) || [] !== $tierDrift) {
                    ++$nonTokenDrift;
                    $entry = sprintf(
                        '%s/%s (ID %d, %s) — DB: in=%.8f/%s out=%.8f/%s | LiteLLM: in=%.8f out=%.8f (per unit)',
                        $service,
                        $model->getProviderId(),
                        $model->getId(),
                        $currentMode,
                        $model->getPriceIn(),
                        $model->getInUnit(),
                        $model->getPriceOut(),
                        $model->getOutUnit(),
                        $pricing['price_in'],
                        $pricing['price_out'],
                    );

                    if ([] !== $tierDrift) {
                        $entry .= sprintf(' | resolution tiers: %s', implode(', ', $tierDrift));
                    }

                    $nonTokenDriftList[] = $entry.$this->sourceSuffix($litellmModel);
                } else {
                    ++$unchanged;
                }
                continue;
            }

            // Case 3 — per_token on both sides: the sync may write (below).
            if ($this->isNullPriceRisk($model, $pricing['price_in'], $pricing['price_out'])) {
                ++$nullPriceSkipped;
                $nullPriceList[] = sprintf(
                    '%s/%s (ID %d) — DB: in=%.2f out=%.2f',
                    $service,
                    $model->getProviderId(),
                    $model->getId(),
                    $model->getPriceIn(),
                    $model->getPriceOut(),
                );
                $this->logger->warning('Price sync: null-price protection triggered', [
                    'model' => $model->getProviderId(),
                    'db_price_in' => $model->getPriceIn(),
                    'litellm_price_in' => $pricing['price_in'],
                ]);
                continue;
            }

            if (!$force) {
                $currentHistory = $this->priceHistoryRepository->findCurrentPrice($model);
                if ($currentHistory && 'admin' === $currentHistory->getSource()) {
                    ++$skipped;
                    continue;
                }
            }

            // Both sides are per_token here (guaranteed by the mode guard above),
            // so only the numeric price can differ.
            $priceChanged = abs($model->getPriceIn() - $pricing['price_in']) > 0.000001
                || abs($model->getPriceOut() - $pricing['price_out']) > 0.000001;

            if (!$priceChanged) {
                ++$unchanged;
                continue;
            }

            if ($dryRun) {
                $unit = $pricing['in_unit'];
                $io->text(sprintf(
                    '[DRY-RUN] %s (%s): in %.6f -> %.6f, out %.6f -> %.6f%s',
                    $model->getProviderId(),
                    $unit,
                    $model->getPriceIn(),
                    $pricing['price_in'],
                    $model->getPriceOut(),
                    $pricing['price_out'],
                    $this->sourceSuffix($litellmModel),
                ));
                ++$updated;
                continue;
            }

            $this->updateModelPrice($model, $pricing);
            ++$updated;

            $io->text(sprintf(
                'Updated %s (%s): in %.6f -> %.6f, out %.6f -> %.6f',
                $model->getProviderId(),
                $pricing['in_unit'],
                $model->getPriceIn(),
                $pricing['price_in'],
                $model->getPriceOut(),
                $pricing['price_out'],
            ));
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        if ([] !== $nullPriceList) {
            $io->section(sprintf('Null-price protected (%d)', count($nullPriceList)));
            $io->listing($nullPriceList);
        }

        if ([] !== $nonTokenDriftList) {
            $io->section(sprintf('Non-per-token price drift — verify & update ModelCatalog.php (%d)', count($nonTokenDriftList)));
            $io->listing($nonTokenDriftList);
        }

        // Section titles below are matched by .github/workflows/price-drift.yml to
        // cut the "act on this" part out of the report — rename them there too.
        if ([] !== $knownDeviationList) {
            $io->section(sprintf('Known LiteLLM deviations — catalog keeps the verified official rate, not drift (%d)', count($knownDeviationList)));
            $io->listing($knownDeviationList);
        }

        if ([] !== $obsoleteDeviationList) {
            $io->section(sprintf('Obsolete LiteLLM deviations — LiteLLM now agrees, remove the registry entry (%d)', count($obsoleteDeviationList)));
            $io->listing($obsoleteDeviationList);
        }

        if ([] !== $modeMismatchList) {
            $io->section(sprintf('Pricing-mode mismatch — structural, manual only (%d)', count($modeMismatchList)));
            $io->listing($modeMismatchList);
        }

        if ([] !== $unmatchedList) {
            $io->section(sprintf('Unmatched — no LiteLLM reference, verify manually (%d)', count($unmatchedList)));
            $io->listing($unmatchedList);
        }

        $totalDrift = $updated + $nonTokenDrift;

        // "unmatched" must stay the last word: the workflow reads the wrapped
        // summary block up to the line containing it.
        $io->success(sprintf(
            'Price sync complete: %d updated, %d non-per-token drift, %d unchanged, %d skipped (admin), %d mode-mismatch, %d null-price protected, %d known-deviation, %d unmatched',
            $updated,
            $nonTokenDrift,
            $unchanged,
            $skipped,
            $modeMismatch,
            $nullPriceSkipped,
            $knownDeviation,
            $notMatched,
        ));

        $this->logger->info('Price sync completed', [
            'updated' => $updated,
            'non_token_drift' => $nonTokenDrift,
            'unchanged' => $unchanged,
            'skipped' => $skipped,
            'mode_mismatch' => $modeMismatch,
            'null_price_skipped' => $nullPriceSkipped,
            'known_deviation' => $knownDeviation,
            'not_matched' => $notMatched,
            'dry_run' => $dryRun,
        ]);

        if ($failOnDrift && $totalDrift > 0) {
            $io->warning(sprintf(
                'Price drift detected: %d per-token model(s) + %d non-per-token model(s) differ from LiteLLM. This is a signal to verify, not a value to copy: check each model against the official provider page (the LiteLLM "source" URL above is a starting point), then either correct ModelCatalog.php or record a LiteLLM error in ModelCatalog::LITELLM_DEVIATIONS. Procedure: docs/PRICING_MAINTENANCE.md.',
                $updated,
                $nonTokenDrift,
            ));

            return self::EXIT_DRIFT_DETECTED;
        }

        return Command::SUCCESS;
    }

    /**
     * Detects a price change for a non-per-token model whose catalog mode matches
     * LiteLLM's. Both sides are normalised to a single billable unit (per second /
     * image / character) via the same converter billing uses, then compared with a
     * relative tolerance — per-second rates are tiny (~3e-5), so an absolute epsilon
     * would flag float noise as drift.
     *
     * On a row with `json.resolution_prices` the headline `priceOut` is NOT
     * compared. Billing charges from the tier table, and the headline is only its
     * fallback, so the tiers are what {@see driftedResolutionTiers} checks one by
     * one. The two sides also author the headline differently — LiteLLM's base
     * rate is its cheapest tier, while the catalog sets the headline to whatever
     * a default render costs (xAI Grok Imagine: 720p) — so comparing them flagged
     * two correctly priced rows as drift (#1772). ModelCatalogTest pins the
     * headline to one of the row's own tiers, so the fallback stays a real rate.
     *
     * @param array{pricing_mode: string, price_in: float, price_out: float, in_unit: string, out_unit: string, cache_price_in: float, mode_prices: array<string, float>} $pricing
     */
    private function nonTokenPriceDrifted(Model $model, array $pricing): bool
    {
        $dbIn = CostCalculationService::normaliseToPerUnit($model->getPriceIn(), $model->getInUnit());

        if ($this->pricesDiffer($dbIn, $pricing['price_in'])) {
            return true;
        }

        if ($this->hasResolutionTiers($model, $pricing)) {
            return false;
        }

        $dbOut = CostCalculationService::normaliseToPerUnit($model->getPriceOut(), $model->getOutUnit());

        return $this->pricesDiffer($dbOut, $pricing['price_out']);
    }

    /**
     * @param array{pricing_mode: string, price_in: float, price_out: float, in_unit: string, out_unit: string, cache_price_in: float, mode_prices: array<string, float>} $pricing
     */
    private function hasResolutionTiers(Model $model, array $pricing): bool
    {
        return 'per_second' === $pricing['pricing_mode']
            && is_array($model->getJson()['resolution_prices'] ?? null);
    }

    /**
     * Classifies LiteLLM's current value against a recorded deviation.
     *
     *  - `pinned`:   LiteLLM still says exactly what the entry recorded — the
     *                verified LiteLLM error persists, nothing to do.
     *  - `obsolete`: LiteLLM now matches the catalog — upstream fixed it, the
     *                entry only adds noise and must be deleted.
     *  - `moved`:    LiteLLM changed to a third value — nobody has verified that
     *                one, so it is ordinary drift.
     *
     * Both prices are pinned, never just one, so an entry can only ever silence
     * the exact pair a human looked at. $ours is the catalog in/out pair in the
     * same unit as $pricing ({@see pricesInLiteLLMUnits}).
     *
     * @param array{litellm_in: float, litellm_out: float, source: string, verifiedOn: string, reason: string}                                                            $deviation
     * @param array{pricing_mode: string, price_in: float, price_out: float, in_unit: string, out_unit: string, cache_price_in: float, mode_prices: array<string, float>} $pricing
     * @param array{0: float, 1: float}                                                                                                                                   $ours
     *
     * @return 'pinned'|'obsolete'|'moved'
     */
    private function deviationVerdict(array $deviation, array $pricing, array $ours): string
    {
        if (!$this->pricesDiffer($pricing['price_in'], $deviation['litellm_in'])
            && !$this->pricesDiffer($pricing['price_out'], $deviation['litellm_out'])) {
            return 'pinned';
        }

        if (!$this->pricesDiffer($pricing['price_in'], $ours[0])
            && !$this->pricesDiffer($pricing['price_out'], $ours[1])) {
            return 'obsolete';
        }

        return 'moved';
    }

    /**
     * The catalog's in/out price in the unit the LiteLLM comparison uses: per 1M
     * tokens for per_token rows (the same assumption the per-token compare makes),
     * per billable unit for media rows.
     *
     * @return array{0: float, 1: float}
     */
    private function pricesInLiteLLMUnits(Model $model, string $pricingMode): array
    {
        if ('per_token' === $pricingMode) {
            return [$model->getPriceIn(), $model->getPriceOut()];
        }

        return [
            CostCalculationService::normaliseToPerUnit($model->getPriceIn(), $model->getInUnit()),
            CostCalculationService::normaliseToPerUnit($model->getPriceOut(), $model->getOutUnit()),
        ];
    }

    /**
     * LiteLLM records where it took a price from (`source`). Printed next to every
     * flagged model so the person verifying starts at the provider's own page
     * instead of searching for it — and so this repo never has to maintain a
     * list of price URLs that go stale.
     *
     * @param array<string, mixed> $litellmModel
     */
    private function sourceSuffix(array $litellmModel): string
    {
        $source = $litellmModel['source'] ?? null;

        return is_string($source) && '' !== $source ? ' | source: '.$source : '';
    }

    /**
     * Compares a resolution-tiered per-second row against LiteLLM tier by tier.
     *
     * `priceOut` only carries the headline rate (the base tier), so comparing it
     * alone is blind to a provider repricing 1080p or 4K on its own — and blind
     * to our own tiers drifting apart from the headline. We therefore walk
     * `json.resolution_prices`, the table billing actually charges from
     * ({@see CostCalculationService::lookupResolutionPrice}), and resolve each
     * tier's upstream rate from `output_cost_per_second_<tier>`. LiteLLM only
     * publishes that key for tiers priced above its base rate, so an absent key
     * means "bills at the base rate" rather than "unknown".
     *
     * @param array{pricing_mode: string, price_in: float, price_out: float, in_unit: string, out_unit: string, cache_price_in: float, mode_prices: array<string, float>} $pricing
     *
     * @return list<string> human-readable "<tier>: <ours> vs <theirs>" entries, empty when every tier agrees
     */
    private function driftedResolutionTiers(Model $model, array $pricing): array
    {
        if ('per_second' !== $pricing['pricing_mode']) {
            return [];
        }

        $catalogTiers = $model->getJson()['resolution_prices'] ?? null;
        if (!is_array($catalogTiers)) {
            return [];
        }

        $drifted = [];

        foreach ($catalogTiers as $tier => $catalogPrice) {
            if (!is_numeric($catalogPrice)) {
                continue;
            }

            $upstream = $pricing['mode_prices'][self::RESOLUTION_TIER_PREFIX.strtolower((string) $tier)]
                ?? $pricing['price_out'];

            if ($this->pricesDiffer((float) $catalogPrice, $upstream)) {
                $drifted[] = sprintf('%s: %.8f vs %.8f', $tier, (float) $catalogPrice, $upstream);
            }
        }

        return $drifted;
    }

    /**
     * True when two per-unit prices differ beyond a 0.1% relative tolerance (with a
     * tiny absolute floor for the zero case).
     */
    private function pricesDiffer(float $a, float $b): bool
    {
        $scale = max(abs($a), abs($b));

        return abs($a - $b) > max(1e-12, $scale * 0.001);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fetchLiteLLMPrices(): array
    {
        $response = $this->httpClient->request('GET', self::LITELLM_URL, [
            'timeout' => 30,
        ]);

        return $response->toArray();
    }

    /**
     * Attempts multiple naming strategies to match a DB model to a LiteLLM key.
     *
     * @param array<string, array<string, mixed>> $litellmData
     */
    private function findLiteLLMKey(Model $model, array $litellmData): ?string
    {
        $providerId = $model->getProviderId();
        $serviceLower = strtolower($model->getService());

        // 1) Direct match (e.g. "gpt-5", "claude-sonnet-4-6", "gemini-2.5-pro")
        if (isset($litellmData[$providerId])) {
            return $providerId;
        }

        // 2) Try all known prefixes for this service
        $prefixes = self::PREFIX_MAP[$serviceLower] ?? [$serviceLower];
        foreach ($prefixes as $prefix) {
            $key = $prefix.'/'.$providerId;
            if (isset($litellmData[$key])) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Extracts pricing data from a LiteLLM model entry, handling all billing modes.
     *
     * @return array{pricing_mode: string, price_in: float, price_out: float, in_unit: string, out_unit: string, cache_price_in: float, mode_prices: array<string, float>}
     */
    private function extractPricing(array $litellmModel): array
    {
        $mode = $litellmModel['mode'] ?? 'chat';

        // TTS: billed per input character
        if ('audio_speech' === $mode && isset($litellmModel['input_cost_per_character'])) {
            $pricePerChar = (float) $litellmModel['input_cost_per_character'];

            return [
                'pricing_mode' => 'per_character',
                'price_in' => $pricePerChar,
                'price_out' => 0.0,
                'in_unit' => 'perChar',
                'out_unit' => 'perChar',
                'cache_price_in' => 0.0,
                'mode_prices' => ['input_cost_per_character' => $pricePerChar],
            ];
        }

        // Transcription: billed per second of audio
        if ('audio_transcription' === $mode && isset($litellmModel['input_cost_per_second'])) {
            $pricePerSec = (float) $litellmModel['input_cost_per_second'];

            return [
                'pricing_mode' => 'per_second',
                'price_in' => $pricePerSec,
                'price_out' => 0.0,
                'in_unit' => 'perSec',
                'out_unit' => 'perSec',
                'cache_price_in' => 0.0,
                'mode_prices' => ['input_cost_per_second' => $pricePerSec],
            ];
        }

        // Image generation with flat per-image pricing (no per-token)
        if ('image_generation' === $mode && isset($litellmModel['output_cost_per_image']) && !isset($litellmModel['input_cost_per_token'])) {
            $pricePerImage = (float) $litellmModel['output_cost_per_image'];

            return [
                'pricing_mode' => 'per_image',
                'price_in' => 0.0,
                'price_out' => $pricePerImage,
                'in_unit' => 'perImage',
                'out_unit' => 'perImage',
                'cache_price_in' => 0.0,
                'mode_prices' => ['output_cost_per_image' => $pricePerImage],
            ];
        }

        // Video generation: billed per second of output. `output_cost_per_second`
        // is the base rate; LiteLLM adds a key per resolution tier that costs more
        // than the base (`output_cost_per_second_1080p`, `_4k`) and omits the ones
        // that bill at the base rate. They are carried in mode_prices so the drift
        // check can compare our json.resolution_prices tier by tier instead of
        // only matching headline against base.
        if ('video_generation' === $mode && isset($litellmModel['output_cost_per_second'])) {
            $pricePerSec = (float) $litellmModel['output_cost_per_second'];
            $modePrices = ['output_cost_per_second' => $pricePerSec];

            foreach ($litellmModel as $key => $value) {
                if (str_starts_with((string) $key, self::RESOLUTION_TIER_PREFIX) && is_numeric($value)) {
                    $modePrices[(string) $key] = (float) $value;
                }
            }

            return [
                'pricing_mode' => 'per_second',
                'price_in' => 0.0,
                'price_out' => $pricePerSec,
                'in_unit' => 'perSec',
                'out_unit' => 'perSec',
                'cache_price_in' => 0.0,
                'mode_prices' => $modePrices,
            ];
        }

        // Rerank: a reranker returns scores, not tokens, so no provider bills an
        // output side — LiteLLM nevertheless mirrors the input rate into
        // `output_cost_per_token` on some entries (Jina), which compared against
        // the catalog's unbilled output read as drift. Cohere bills per request
        // (`input_cost_per_query`); billing has no such mode, so that is reported
        // as a structural mismatch rather than squeezed into per-token.
        if ('rerank' === $mode) {
            $perQuery = (float) ($litellmModel['input_cost_per_query'] ?? 0.0);

            if ($perQuery > 0.0) {
                return [
                    'pricing_mode' => self::MODE_PER_REQUEST,
                    'price_in' => $perQuery,
                    'price_out' => 0.0,
                    'in_unit' => 'perRequest',
                    'out_unit' => 'perRequest',
                    'cache_price_in' => 0.0,
                    'mode_prices' => ['input_cost_per_query' => $perQuery],
                ];
            }

            return [
                'pricing_mode' => 'per_token',
                'price_in' => $this->extractPricePerMillion($litellmModel, 'input_cost_per_token'),
                'price_out' => 0.0,
                'in_unit' => 'per1M',
                'out_unit' => 'per1M',
                'cache_price_in' => 0.0,
                'mode_prices' => [],
            ];
        }

        // Default: token-based pricing (chat, embedding, token-based image gen, token-based TTS)
        $priceIn = $this->extractPricePerMillion($litellmModel, 'input_cost_per_token');
        $priceOut = $this->extractPricePerMillion($litellmModel, 'output_cost_per_token');
        $cachePrice = $this->extractPricePerMillion($litellmModel, 'cache_read_input_token_cost');

        return [
            'pricing_mode' => 'per_token',
            'price_in' => $priceIn,
            'price_out' => $priceOut,
            'in_unit' => 'per1M',
            'out_unit' => 'per1M',
            'cache_price_in' => $cachePrice,
            'mode_prices' => [],
        ];
    }

    private function extractPricePerMillion(array $litellmModel, string $key): float
    {
        $perToken = $litellmModel[$key] ?? null;
        if (null === $perToken || 0.0 === (float) $perToken) {
            return 0.0;
        }

        return (float) $perToken * 1_000_000;
    }

    private function isNullPriceRisk(Model $model, float $newPriceIn, float $newPriceOut): bool
    {
        $hasExistingPrice = $model->getPriceIn() > 0.000001 || $model->getPriceOut() > 0.000001;
        $newPriceIsZero = $newPriceIn < 0.000001 && $newPriceOut < 0.000001;

        return $hasExistingPrice && $newPriceIsZero;
    }

    /**
     * @param array{pricing_mode: string, price_in: float, price_out: float, in_unit: string, out_unit: string, cache_price_in: float, mode_prices: array<string, float>} $pricing
     */
    private function updateModelPrice(Model $model, array $pricing): void
    {
        $now = new \DateTime();

        $this->priceHistoryRepository->closeCurrentPrice($model, $now);

        $entry = new ModelPriceHistory();
        $entry->setModel($model)
            ->setPriceIn(number_format($pricing['price_in'], 8, '.', ''))
            ->setPriceOut(number_format($pricing['price_out'], 8, '.', ''))
            ->setInUnit($pricing['in_unit'])
            ->setOutUnit($pricing['out_unit'])
            ->setSource('litellm')
            ->setValidFrom($now);

        if ($pricing['cache_price_in'] > 0) {
            $entry->setCachePriceIn(number_format($pricing['cache_price_in'], 8, '.', ''));
        }

        $this->em->persist($entry);

        $model->setPriceIn($pricing['price_in']);
        $model->setPriceOut($pricing['price_out']);
        $model->setInUnit($pricing['in_unit']);
        $model->setOutUnit($pricing['out_unit']);

        $json = $model->getJson();
        $json['pricing_mode'] = $pricing['pricing_mode'];

        if ([] !== $pricing['mode_prices']) {
            $json['mode_prices'] = $pricing['mode_prices'];
        }

        if ($pricing['cache_price_in'] > 0) {
            $json['cache_read_price_per_1M'] = $pricing['cache_price_in'];
        }

        $model->setJson($json);
    }
}
