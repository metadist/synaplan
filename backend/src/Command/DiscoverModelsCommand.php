<?php

declare(strict_types=1);

namespace App\Command;

use App\AI\Service\ProviderModelListing;
use App\Service\DiscordNotificationService;
use App\Service\ModelDiscovery\ModelDiscoveryReport;
use App\Service\ModelDiscovery\ModelDiscoveryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Detects newly listed upstream AI models not yet on this install.
 *
 * Detection only: never writes BMODELS or ModelCatalog. Opt-in via
 * MODEL_DISCOVERY_ENABLED (default off) so self-hosted installs never make
 * these requests. See docs/PRICING_MAINTENANCE.md §"New model detection".
 */
#[AsCommand(
    name: 'app:models:discover',
    description: 'Detect newly listed upstream AI models not yet in this install\'s BMODELS',
)]
final class DiscoverModelsCommand extends Command
{
    public function __construct(
        private readonly ModelDiscoveryService $discovery,
        private readonly DiscordNotificationService $discord,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('notify', null, InputOption::VALUE_NONE, 'Send a Discord alert when there is something to report (no-op without DISCORD_WEBHOOK_URL)')
            ->setHelp(<<<'HELP'
            Fetches each key-configured provider's own model list and reports ids
            that appeared after this install's per-provider baseline and are neither
            in BMODELS (including inactive/retired) nor in ModelDiscoveryIgnoreList.

            Opt-in: set <info>MODEL_DISCOVERY_ENABLED=true</info>. When disabled the
            command exits 0 without any outbound request.

            Nothing is written to the catalog. Resolve each pending id by adding a
            ModelCatalog row (docs/PRICING_MAINTENANCE.md) or a reasoned ignore entry.
            HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->discovery->isEnabled()) {
            $io->note('Model discovery is disabled (MODEL_DISCOVERY_ENABLED=false). No providers were contacted.');

            return Command::SUCCESS;
        }

        try {
            $report = $this->discovery->run();
        } catch (\Throwable $e) {
            $io->error('New-model check could not run: '.$e->getMessage());
            if ($input->getOption('notify')) {
                $this->notifyFailure($io, $e->getMessage());
            }

            return Command::FAILURE;
        }

        $this->renderProviders($io, $report);
        $this->renderBaselines($io, $report);
        $this->renderPending($io, $report);
        $this->renderFailed($io, $report);
        $this->renderObsolete($io, $report);

        $io->success(sprintf(
            'Model discovery complete: %d pending, %d baseline(s) recorded, %d unreachable, %d obsolete ignore(s)',
            count($report->pending),
            count($report->baselinesRecorded),
            count($report->failedProviders),
            count($report->obsoleteIgnores),
        ));

        if ($input->getOption('notify') && $report->shouldNotify) {
            $this->notify($io, $report);
        }

        return Command::SUCCESS;
    }

    private function renderProviders(SymfonyStyle $io, ModelDiscoveryReport $report): void
    {
        $rows = [];
        foreach ($report->providers as $state) {
            $rows[] = [
                $state['provider'],
                match ($state['status']) {
                    ProviderModelListing::STATUS_OK => sprintf(
                        'ok — %d listed, %d pending',
                        $state['listedCount'],
                        $state['pendingCount'],
                    ),
                    ProviderModelListing::STATUS_NOT_CONFIGURED => 'skipped — no API key',
                    ProviderModelListing::STATUS_NO_LISTING_ENDPOINT => 'skipped — no listing endpoint',
                    default => 'unreachable — '.($state['detail'] ?? 'error'),
                },
            ];
        }

        $io->table(['Provider', 'Status'], $rows);
    }

    private function renderBaselines(SymfonyStyle $io, ModelDiscoveryReport $report): void
    {
        if ([] === $report->baselinesRecorded) {
            return;
        }

        $io->section('Baselines recorded');
        $lines = [];
        foreach ($report->baselinesRecorded as $event) {
            $lines[] = sprintf(
                '%s — %d ids baseline; new models will be reported from tomorrow',
                $event['provider'],
                $event['idCount'],
            );
        }
        $io->listing($lines);
    }

    private function renderPending(SymfonyStyle $io, ModelDiscoveryReport $report): void
    {
        $io->section(sprintf('Pending new models (%d)', count($report->pending)));
        if ([] === $report->pending) {
            $io->text('none');

            return;
        }

        $lines = [];
        foreach ($report->pending as $item) {
            $lines[] = sprintf(
                '%s `%s` — first seen %s (%d day%s pending)',
                $item['provider'],
                $item['id'],
                $item['firstSeen'],
                $item['daysPending'],
                1 === $item['daysPending'] ? '' : 's',
            );
        }
        $io->listing($lines);
    }

    private function renderFailed(SymfonyStyle $io, ModelDiscoveryReport $report): void
    {
        if ([] === $report->failedProviders) {
            return;
        }

        $io->section('Could not check');
        $lines = [];
        foreach ($report->failedProviders as $fail) {
            $lines[] = sprintf('could not check %s: %s', $fail['provider'], $fail['detail']);
        }
        $io->listing($lines);
    }

    private function renderObsolete(SymfonyStyle $io, ModelDiscoveryReport $report): void
    {
        if ([] === $report->obsoleteIgnores) {
            return;
        }

        $io->section(sprintf('Obsolete ignore entries (%d)', count($report->obsoleteIgnores)));
        $lines = [];
        foreach ($report->obsoleteIgnores as $entry) {
            $why = 'gone_upstream' === $entry['why']
                ? 'no longer in the provider list — delete this entry'
                : 'matching id is now in BMODELS — delete this entry';
            $lines[] = sprintf('%s — %s', $entry['key'], $why);
        }
        $io->listing($lines);
    }

    private function notify(SymfonyStyle $io, ModelDiscoveryReport $report): void
    {
        if (!$this->discord->isEnabled()) {
            $io->note('Discord notifications are disabled (no DISCORD_WEBHOOK_URL); reported to the console only.');
            $this->discovery->markBaselinesAnnounced($this->baselineProviders($report));

            return;
        }

        if (!$this->discovery->claimNotifyDay()) {
            $io->note('Discord already claimed for today by another node; skipped post.');

            return;
        }

        $posted = $this->discord->notifyNewModelDiscovery(
            $this->pendingLines($report),
            $this->failedLines($report),
            $this->baselineLines($report),
        );
        if (!$posted) {
            $this->discovery->releaseNotifyDay();
            $io->warning('Discord post failed; today stays unclaimed so the next run retries.');

            return;
        }

        $this->discovery->markBaselinesAnnounced($this->baselineProviders($report));
        $io->note('Discord alert sent.');
    }

    private function notifyFailure(SymfonyStyle $io, string $reason): void
    {
        if (!$this->discord->isEnabled()) {
            return;
        }

        if (!$this->discovery->claimNotifyDay()) {
            $io->note('Discord already claimed for today by another node; skipped failure post.');

            return;
        }

        $posted = $this->discord->notifyNewModelDiscoveryFailure($reason);
        if (!$posted) {
            $this->discovery->releaseNotifyDay();
            $io->warning('Discord post failed; today stays unclaimed so the next run retries.');

            return;
        }

        $io->note('Discord failure alert sent.');
    }

    /**
     * @return list<string>
     */
    private function baselineProviders(ModelDiscoveryReport $report): array
    {
        $providers = [];
        foreach ($report->baselinesRecorded as $event) {
            $providers[] = $event['provider'];
        }

        return $providers;
    }

    /**
     * @return list<string>
     */
    private function pendingLines(ModelDiscoveryReport $report): array
    {
        $lines = [];
        foreach ($report->pending as $item) {
            $lines[] = sprintf(
                '%s `%s` — first seen %s (%d day%s)',
                $item['provider'],
                $item['id'],
                $item['firstSeen'],
                $item['daysPending'],
                1 === $item['daysPending'] ? '' : 's',
            );
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function failedLines(ModelDiscoveryReport $report): array
    {
        $lines = [];
        foreach ($report->failedProviders as $fail) {
            $lines[] = sprintf('could not check %s: %s', $fail['provider'], $fail['detail']);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function baselineLines(ModelDiscoveryReport $report): array
    {
        if ([] === $report->baselinesRecorded) {
            return [];
        }

        $parts = [];
        $total = 0;
        foreach ($report->baselinesRecorded as $event) {
            $parts[] = $event['provider'];
            $total += $event['idCount'];
        }

        return [sprintf(
            'Baseline recorded for %s: %d ids; new models will be reported from tomorrow',
            implode(', ', $parts),
            $total,
        )];
    }
}
