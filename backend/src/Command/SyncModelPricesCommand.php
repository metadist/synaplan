<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Model;
use App\Entity\ModelPriceHistory;
use App\Repository\ModelPriceHistoryRepository;
use App\Repository\ModelRepository;
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
    private const LITELLM_URL = 'https://raw.githubusercontent.com/BerriAI/litellm/main/model_prices_and_context_window.json';

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

    public function __construct(
        private HttpClientInterface $httpClient,
        private ModelRepository $modelRepository,
        private ModelPriceHistoryRepository $priceHistoryRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show changes without applying')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Override admin-set prices')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Only sync a specific provider');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
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
        $notMatched = 0;
        $unmatchedList = [];
        $nullPriceList = [];

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

            $priceChanged = abs($model->getPriceIn() - $pricing['price_in']) > 0.000001
                || abs($model->getPriceOut() - $pricing['price_out']) > 0.000001;

            // Also check if pricing_mode changed (e.g. first time setting mode metadata)
            $currentJson = $model->getJson();
            $modeChanged = ($currentJson['pricing_mode'] ?? 'per_token') !== $pricing['pricing_mode'];

            if (!$priceChanged && !$modeChanged) {
                ++$unchanged;
                continue;
            }

            if ($dryRun) {
                $unit = $pricing['in_unit'];
                $io->text(sprintf(
                    '[DRY-RUN] %s (%s): in %.6f -> %.6f, out %.6f -> %.6f',
                    $model->getProviderId(),
                    $unit,
                    $model->getPriceIn(),
                    $pricing['price_in'],
                    $model->getPriceOut(),
                    $pricing['price_out'],
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

        if ([] !== $unmatchedList) {
            $io->section(sprintf('Unmatched models (%d)', count($unmatchedList)));
            $io->listing($unmatchedList);
        }

        $io->success(sprintf(
            'Price sync complete: %d updated, %d unchanged, %d skipped (admin), %d null-price protected, %d unmatched',
            $updated,
            $unchanged,
            $skipped,
            $nullPriceSkipped,
            $notMatched,
        ));

        $this->logger->info('Price sync completed', [
            'updated' => $updated,
            'unchanged' => $unchanged,
            'skipped' => $skipped,
            'null_price_skipped' => $nullPriceSkipped,
            'not_matched' => $notMatched,
            'dry_run' => $dryRun,
        ]);

        return Command::SUCCESS;
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

        // Video generation: billed per second of output
        if ('video_generation' === $mode && isset($litellmModel['output_cost_per_second'])) {
            $pricePerSec = (float) $litellmModel['output_cost_per_second'];

            return [
                'pricing_mode' => 'per_second',
                'price_in' => 0.0,
                'price_out' => $pricePerSec,
                'in_unit' => 'perSec',
                'out_unit' => 'perSec',
                'cache_price_in' => 0.0,
                'mode_prices' => ['output_cost_per_second' => $pricePerSec],
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
        $now = new \DateTimeImmutable();

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
