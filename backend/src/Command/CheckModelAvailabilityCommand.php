<?php

declare(strict_types=1);

namespace App\Command;

use App\AI\Service\ModelAvailabilityChecker;
use App\AI\Service\ProviderModelListing;
use App\Service\DiscordNotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Detects models a provider no longer serves, before users hit the error.
 *
 * Read-only by design: it never deactivates a row. A model can vanish from a
 * listing for reasons other than discontinuation (rename, region gate, account
 * tier), and an unattended `BACTIVE = 0` on a false positive would take a
 * working model away from every user of the install. Retirement stays a
 * reviewed migration — see `docs/PRICING_MAINTENANCE.md`.
 */
#[AsCommand(
    name: 'app:models:check-availability',
    description: 'Check whether providers still serve the models we offer (discontinuation detection)',
)]
final class CheckModelAvailabilityCommand extends Command
{
    /**
     * Exit code for "we offer models the provider no longer serves". Distinct
     * from Command::FAILURE (1, the command itself broke) so a scheduled run
     * can tell the two apart.
     */
    private const EXIT_DRIFT_DETECTED = 2;

    public function __construct(
        private readonly ModelAvailabilityChecker $checker,
        private readonly DiscordNotificationService $discord,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('fail-on-drift', null, InputOption::VALUE_NONE, 'Exit with code 2 if any offered model is missing from its provider listing')
            ->addOption('notify', null, InputOption::VALUE_NONE, 'Send a Discord alert when models are missing (no-op without DISCORD_WEBHOOK_URL)')
            ->setHelp(<<<'HELP'
            Queries every key-configured provider for its current model list, then asks the
            provider directly about each model that is missing from that list. Only models
            the provider itself reports as unknown are treated as discontinued — listings
            legitimately omit live models, so list membership alone is not evidence.

            Two scopes are reported independently:
              <info>database</info>  this install still offers the model (active BMODELS row)
              <info>catalog</info>   we still ship the model to new installs (ModelCatalog)

            Providers without an API key, without a listing endpoint, or that could not be
            reached are reported as unchecked and never produce findings — an unreachable
            API must not look like an empty catalog.

            Nothing is changed. Retire a confirmed dead model with a reviewed migration.
            HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $report = $this->checker->run();
        } catch (\Throwable $e) {
            $io->error('Model availability check failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        $this->renderProviders($io, $report['providers']);

        $checked = array_filter(
            $report['providers'],
            static fn (array $p): bool => ProviderModelListing::STATUS_OK === $p['status'],
        );

        if ([] === $checked) {
            $io->warning('No provider could be checked. Availability is unknown, not confirmed — configure at least one API key.');

            return Command::SUCCESS;
        }

        $confirmed = array_values(array_filter($report['findings'], static fn (array $f): bool => $f['confirmed']));
        $unverified = array_values(array_filter($report['findings'], static fn (array $f): bool => !$f['confirmed']));

        if ([] !== $unverified) {
            $this->renderUnverified($io, $unverified);
        }

        if ([] === $confirmed) {
            $io->success(sprintf('All models offered by the %d checked provider(s) are still served upstream.', count($checked)));

            return Command::SUCCESS;
        }

        $this->renderConfirmed($io, $confirmed);

        if ($input->getOption('notify')) {
            $this->notify($io, $confirmed, $report['providers']);
        }

        return $input->getOption('fail-on-drift') ? self::EXIT_DRIFT_DETECTED : Command::SUCCESS;
    }

    /**
     * @param array<string, array{status: string, detail: string|null, servedCount: int, matchedCount: int, offeredCount: int}> $providers
     */
    private function renderProviders(SymfonyStyle $io, array $providers): void
    {
        $rows = [];
        foreach ($providers as $provider => $state) {
            $rows[] = [
                $provider,
                match ($state['status']) {
                    ProviderModelListing::STATUS_OK => sprintf(
                        'checked — %d served, %d/%d of ours matched',
                        $state['servedCount'],
                        $state['matchedCount'],
                        $state['offeredCount'],
                    ),
                    ProviderModelListing::STATUS_NOT_CONFIGURED => 'skipped — no API key',
                    ProviderModelListing::STATUS_NO_LISTING_ENDPOINT => 'unchecked — no listing endpoint',
                    default => 'unchecked — '.($state['detail'] ?? 'unreachable'),
                },
            ];
        }

        $io->table(['Provider', 'Status'], $rows);
    }

    /**
     * @param list<array{provider: string, providerId: string, name: string, tag: string, bids: list<int>, scopes: list<string>, recommended: bool, confirmed: bool}> $findings
     */
    private function renderConfirmed(SymfonyStyle $io, array $findings): void
    {
        $io->table(
            ['Provider', 'Provider model id', 'Name', 'Tag', 'BID', 'Scope', 'Provider default'],
            array_map(static fn (array $f): array => [
                $f['provider'],
                $f['providerId'],
                $f['name'],
                $f['tag'],
                implode(', ', array_map('strval', $f['bids'])),
                implode(' + ', $f['scopes']),
                $f['recommended'] ? 'yes' : '',
            ], $findings),
        );

        $io->warning(sprintf('%d model(s) are offered but no longer served by their provider.', count($findings)));
        $io->writeln([
            'Verify each one against the provider\'s deprecation page, then retire it with a',
            'migration — deactivate, never delete: see <info>docs/PRICING_MAINTENANCE.md</info>.',
            '',
            'Rows marked as a provider default are urgent: <info>app:provider:apply-defaults --auto</info>',
            'assigns them unattended at container start.',
            '',
        ]);
    }

    /**
     * @param list<array{provider: string, providerId: string, name: string, tag: string, bids: list<int>, scopes: list<string>, recommended: bool, confirmed: bool}> $findings
     */
    private function renderUnverified(SymfonyStyle $io, array $findings): void
    {
        $io->table(
            ['Provider', 'Provider model id', 'Tag', 'BID'],
            array_map(static fn (array $f): array => [
                $f['provider'],
                $f['providerId'],
                $f['tag'],
                implode(', ', array_map('strval', $f['bids'])),
            ], $findings),
        );

        $io->note([
            sprintf('%d model(s) are absent from their provider listing, but asking the provider', count($findings)),
            'about them directly gave no usable answer (rate limit, auth or outage).',
            'Their availability is unknown, not disproven, so they are NOT reported as',
            'discontinued. Re-run later if they keep appearing here.',
        ]);
    }

    /**
     * @param list<array{provider: string, providerId: string, name: string, tag: string, bids: list<int>, scopes: list<string>, recommended: bool, confirmed: bool}> $findings
     * @param array<string, array{status: string, detail: string|null, servedCount: int, matchedCount: int, offeredCount: int}>                                       $providers
     */
    private function notify(SymfonyStyle $io, array $findings, array $providers): void
    {
        if (!$this->discord->isEnabled()) {
            $io->note('Discord notifications are disabled (no DISCORD_WEBHOOK_URL); reported to the console only.');

            return;
        }

        $missing = [];
        foreach ($findings as $finding) {
            $missing[] = sprintf(
                '%s `%s`%s — BID %s (%s)',
                $finding['provider'],
                $finding['providerId'],
                $finding['recommended'] ? ' **[provider default]**' : '',
                implode('/', array_map('strval', $finding['bids'])),
                implode(' + ', $finding['scopes']),
            );
        }

        $unchecked = [];
        foreach ($providers as $provider => $state) {
            if (ProviderModelListing::STATUS_OK !== $state['status']) {
                $unchecked[] = $provider;
            }
        }

        $this->discord->notifyModelAvailabilityDrift($missing, $unchecked);
        $io->note('Discord alert sent.');
    }
}
