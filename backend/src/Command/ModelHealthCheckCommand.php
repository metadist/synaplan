<?php

declare(strict_types=1);

namespace App\Command;

use App\AI\Health\ModelHealthConfig;
use App\AI\Health\ModelHealthEvaluator;
use App\AI\Health\ModelHealthState;
use App\AI\Health\ModelHealthVerdict;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Checks which AI models the configured providers still offer, and reports the
 * ones that stopped working.
 *
 * Costs nothing to run: every provider is asked exactly once, through its free
 * "list your models" endpoint. No inference happens here — pinging the video
 * models alone would run into five figures a month.
 */
#[AsCommand(
    name: 'app:model:health-check',
    description: 'Check AI model availability via free provider catalog endpoints and alert on outages',
)]
final class ModelHealthCheckCommand extends Command
{
    /** Longest random start delay the scheduler may ask for, in seconds. */
    private const MAX_JITTER_SECONDS = 300;

    public function __construct(
        private readonly ModelHealthEvaluator $evaluator,
        private readonly ModelHealthConfig $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Probe and report without writing state, disabling models or sending alerts')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only check this provider (repeatable)')
            ->addOption('jitter', null, InputOption::VALUE_REQUIRED, 'Wait a random 0..N seconds before starting, so scheduled runs do not all hit the providers at once')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Run even when MODELHEALTH.ENABLED is off');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if (!$this->config->isEnabled() && !$input->getOption('force')) {
            $io->note('Model health monitoring is switched off (MODELHEALTH.ENABLED). Use --force to run anyway.');

            return Command::SUCCESS;
        }

        $this->sleepForJitter($input, $io);

        /** @var list<string> $providers */
        $providers = array_values(array_filter(
            (array) $input->getOption('provider'),
            static fn (mixed $value): bool => is_string($value) && '' !== trim($value)
        ));

        $io->title($dryRun ? 'Model health check (dry run)' : 'Model health check');

        $run = $this->evaluator->run($dryRun, $providers);

        if ([] === $run->verdicts) {
            $io->warning('No models found to check.');

            return Command::SUCCESS;
        }

        $this->renderSummary($io, $run->verdicts);
        $this->renderProblems($io, $run->verdicts);

        foreach ($run->skippedProviders as $provider => $reason) {
            $io->writeln(sprintf('  <comment>skipped</comment> %s — %s', $provider, $reason));
        }

        foreach ($run->autoDisabled() as $verdict) {
            $io->writeln(sprintf('  <error>disabled</error> %s (%s)', $verdict->modelName, $verdict->service));
        }
        foreach ($run->reEnabled() as $verdict) {
            $io->writeln(sprintf('  <info>re-enabled</info> %s (%s)', $verdict->modelName, $verdict->service));
        }

        $io->newLine();
        if ($dryRun) {
            $io->note(sprintf(
                'Dry run: nothing was written. Would have raised %d alert(s) and resolved %d.',
                count($run->alertsRaised),
                count($run->alertsResolved),
            ));

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Checked %d model(s). Raised %d alert(s), resolved %d.',
            count($run->verdicts),
            count($run->alertsRaised),
            count($run->alertsResolved),
        ));

        return Command::SUCCESS;
    }

    /**
     * Spread scheduled runs out in time. Every install polls the same handful of
     * provider APIs, and a fleet that all wakes up on the same cron minute is a
     * good way to get rate-limited by them.
     */
    private function sleepForJitter(InputInterface $input, SymfonyStyle $io): void
    {
        $jitter = $input->getOption('jitter');
        if (!is_string($jitter) && !is_int($jitter)) {
            return;
        }

        $max = min(self::MAX_JITTER_SECONDS, max(0, (int) $jitter));
        if ($max <= 0) {
            return;
        }

        $delay = random_int(0, $max);
        if ($delay > 0) {
            $io->writeln(sprintf('<comment>Waiting %ds before probing providers.</comment>', $delay));
            sleep($delay);
        }
    }

    /**
     * @param list<ModelHealthVerdict> $verdicts
     */
    private function renderSummary(SymfonyStyle $io, array $verdicts): void
    {
        $rows = [];
        foreach (ModelHealthState::cases() as $state) {
            $count = count(array_filter($verdicts, static fn (ModelHealthVerdict $v): bool => $v->state === $state));
            if ($count > 0) {
                $rows[] = [$state->value, $count];
            }
        }

        $io->table(['State', 'Models'], $rows);
    }

    /**
     * @param list<ModelHealthVerdict> $verdicts
     */
    private function renderProblems(SymfonyStyle $io, array $verdicts): void
    {
        $problems = array_filter($verdicts, static fn (ModelHealthVerdict $v): bool => $v->needsAttention());
        if ([] === $problems) {
            return;
        }

        $io->section('Models needing attention');
        $io->table(
            ['Provider', 'Model', 'Capability', 'State', 'Reason'],
            array_map(
                static fn (ModelHealthVerdict $v): array => [
                    $v->service,
                    $v->modelName,
                    $v->tag,
                    $v->state->value,
                    mb_substr($v->message, 0, 80),
                ],
                array_values($problems)
            )
        );
    }
}
