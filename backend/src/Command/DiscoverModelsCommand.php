<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\ModelCatalog;
use App\Model\ModelDiscoveryIgnoreList;
use App\Service\ModelDiscovery\ModelDiscoveryMatcher;
use App\Service\ModelDiscovery\ModelDiscoveryUnavailableException;
use App\Service\ModelDiscovery\OpenRouterModelSource;
use App\Service\ModelDiscovery\UpstreamModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Detects newly listed first-party models on OpenRouter that our catalog lacks.
 *
 * Detection only: never writes the catalog, the database, or any price. A human
 * verifies the official provider price page and either adds catalog rows or
 * records a reasoned ignore entry. OpenRouter prices are hints, not authority.
 */
#[AsCommand(
    name: 'app:models:discover',
    description: 'Detect newly listed upstream AI models not yet in ModelCatalog (OpenRouter hint source)',
)]
final class DiscoverModelsCommand extends Command
{
    /**
     * Exit code when --fail-on-new is set and new upstream models were found.
     * Distinct from Command::FAILURE (1, source unavailable/malformed).
     */
    private const EXIT_NEW_MODELS = 2;

    public function __construct(
        private readonly OpenRouterModelSource $source,
        private readonly ModelDiscoveryMatcher $matcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'window-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Only consider upstream models created within this many days',
                (string) ModelDiscoveryMatcher::DEFAULT_WINDOW_DAYS,
            )
            ->addOption(
                'fail-on-new',
                null,
                InputOption::VALUE_NONE,
                'Exit with code 2 when new upstream models are found (for the scheduled CI check)',
            )
            ->setHelp(<<<'HELP'
            Fetches OpenRouter's public model list and reports first-party models
            (Anthropic, OpenAI, Google, xAI, Mistral) created within the window that
            are neither in ModelCatalog nor in ModelDiscoveryIgnoreList.

            Nothing is written. Verify each finding on the official provider price
            page, then add catalog rows or a reasoned ignore entry. See
            <info>docs/PRICING_MAINTENANCE.md</info> §"New model detection".
            HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $windowDays = max(1, (int) $input->getOption('window-days'));
        $failOnNew = (bool) $input->getOption('fail-on-new');

        try {
            $upstream = $this->source->fetch();
        } catch (ModelDiscoveryUnavailableException $e) {
            $io->error('Model discovery could not run: '.$e->getMessage());

            return Command::FAILURE;
        }

        $result = $this->matcher->match(
            $upstream,
            $this->catalogRows(),
            ModelDiscoveryIgnoreList::ENTRIES,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            $windowDays,
        );

        $this->renderNew($io, $result->new);
        $this->renderIgnored($io, $result->ignored);
        $this->renderObsolete($io, $result->obsoleteIgnores);

        $io->success(sprintf(
            'Model discovery complete: %d new, %d ignored, %d obsolete ignore entries, %d-day window, %d upstream models read',
            count($result->new),
            count($result->ignored),
            count($result->obsoleteIgnores),
            $windowDays,
            count($upstream),
        ));

        if ($failOnNew && [] !== $result->new) {
            return self::EXIT_NEW_MODELS;
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<array{service: string, providerId: string}>
     */
    private function catalogRows(): array
    {
        $rows = [];
        foreach (ModelCatalog::all() as $row) {
            $service = (string) ($row['service'] ?? '');
            $providerId = (string) ($row['providerId'] ?? '');
            if ('' === $service || '' === $providerId) {
                continue;
            }
            $rows[] = [
                'service' => $service,
                'providerId' => $providerId,
            ];
        }

        return $rows;
    }

    /**
     * @param list<UpstreamModel> $models
     */
    private function renderNew(SymfonyStyle $io, array $models): void
    {
        $io->section(sprintf('New upstream models (%d)', count($models)));
        if ([] === $models) {
            $io->text('none');

            return;
        }

        $io->listing(array_map($this->formatNewLine(...), $models));
    }

    /**
     * @param list<array{model: UpstreamModel, reason: string, decidedOn: string}> $ignored
     */
    private function renderIgnored(SymfonyStyle $io, array $ignored): void
    {
        $io->section(sprintf('Ignored (%d)', count($ignored)));
        if ([] === $ignored) {
            $io->text('none');

            return;
        }

        $lines = [];
        foreach ($ignored as $entry) {
            $model = $entry['model'];
            $service = ModelDiscoveryMatcher::VENDOR_MAP[$model->vendor] ?? $model->vendor;
            $lines[] = sprintf(
                '%s (%s) — ignored %s — %s',
                $model->openRouterId,
                $service,
                $entry['decidedOn'],
                $entry['reason'],
            );
        }
        $io->listing($lines);
    }

    /**
     * @param list<array{openRouterId: string, reason: string, decidedOn: string, why: 'gone_upstream'|'now_in_catalog'}> $obsolete
     */
    private function renderObsolete(SymfonyStyle $io, array $obsolete): void
    {
        $io->section(sprintf('Obsolete ignore entries (%d)', count($obsolete)));
        if ([] === $obsolete) {
            $io->text('none');

            return;
        }

        $lines = [];
        foreach ($obsolete as $entry) {
            $why = 'gone_upstream' === $entry['why']
                ? 'no longer in the upstream list — delete this entry'
                : 'matching key is now in the catalog — delete this entry';
            $lines[] = sprintf('%s — %s', $entry['openRouterId'], $why);
        }
        $io->listing($lines);
    }

    private function formatNewLine(UpstreamModel $model): string
    {
        $service = ModelDiscoveryMatcher::VENDOR_MAP[$model->vendor] ?? $model->vendor;
        $listed = $model->created->format('Y-m-d');
        $prices = sprintf(
            'in %s / out %s / cache %s',
            $this->formatPrice($model->priceInPer1M),
            $this->formatPrice($model->priceOutPer1M),
            $this->formatPrice($model->cacheReadPer1M),
        );

        return sprintf(
            '%s (%s) — listed %s — OpenRouter hint per 1M: %s — verify on the official price page',
            $model->openRouterId,
            $service,
            $listed,
            $prices,
        );
    }

    private function formatPrice(?float $price): string
    {
        if (null === $price) {
            return 'n/a';
        }

        return number_format($price, 2, '.', '');
    }
}
