<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Media\MediaJobDispatcher;
use App\Service\Media\MediaJobService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-arms the background advancer for one or more {@see \App\Service\Media\MediaJob}s.
 *
 * Why this exists: an `AdvanceMediaJobCommand` can be silently lost — most
 * commonly when the worker is restarted or briefly mis-configured (the
 * classic case being a Redis-namespace mismatch between backend and worker:
 * the message is consumed but `findByKey()` returns null because the two
 * halves write/read under different `synaplan:{env}:` prefixes). In that
 * situation the job sits `queued` until the reaper times it out 20 minutes
 * later, even though the underlying problem was fixed in seconds.
 *
 * Usage:
 *
 *   # Re-arm one specific job (after a deploy / config fix):
 *   php bin/console app:media:advance-jobs 8a0eca169e1d2f7fb24a454973cc1034
 *
 *   # Re-arm every active job (queued/submitting/running/finalizing) — safe
 *   # because the worker locks per-job before advancing, so duplicates are
 *   # idempotent:
 *   php bin/console app:media:advance-jobs --all
 */
#[AsCommand(
    name: 'app:media:advance-jobs',
    description: 'Re-dispatch the background advancer for stuck media jobs',
)]
final class AdvanceMediaJobsCommand extends Command
{
    public function __construct(
        private readonly MediaJobService $jobService,
        private readonly MediaJobDispatcher $dispatcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('jobKey', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Job key(s) to advance')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Re-advance every active job')
            ->addOption(
                'recover',
                null,
                InputOption::VALUE_NONE,
                'Re-open wrongly-failed video jobs (with a provider operation handle) and re-poll the provider before advancing',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $jobKeys */
        $jobKeys = (array) $input->getArgument('jobKey');
        $all = (bool) $input->getOption('all');
        $recover = (bool) $input->getOption('recover');

        if (!$all && [] === $jobKeys) {
            $io->error('Pass at least one job key or use --all.');

            return Command::INVALID;
        }

        if ($all) {
            // Active set = anything currently in the heartbeat ZSET. Avoids
            // pulling terminal jobs (their snapshot is kept for the poll
            // grace window but they have nothing left to advance).
            $jobs = $this->jobService->findPastDeadline(1000);
            // Also pull jobs with a fresh heartbeat that may still need a kick
            // after a worker restart (limit 1000 — generous, well within
            // active-set sizing for any single host).
            foreach ($this->jobService->findStale(time() + 1, 1000) as $job) {
                $jobs[] = $job;
            }
            // Dedupe by key in case the two queries overlap.
            $seen = [];
            foreach ($jobs as $job) {
                $key = $job->getJobKey();
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $jobKeys[] = $key;
            }
        }

        $dispatched = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($jobKeys as $jobKey) {
            $job = $this->jobService->findByKey($jobKey);
            if (null === $job) {
                $io->warning(sprintf('Job %s not found (Redis snapshot expired?).', $jobKey));
                ++$skipped;
                continue;
            }
            if ($job->isTerminal()) {
                // --recover: re-open a wrongly-failed video job (one that still
                // has a provider operation handle) so the advancer re-polls the
                // provider and can finalize a render that actually succeeded.
                if ($recover && $this->jobService->reopenForRecovery($job)) {
                    $io->writeln(sprintf('Re-opened <info>%s</info> for recovery (was %s).', $jobKey, $job->getStatus()));
                } else {
                    $io->writeln(sprintf('Skipping <comment>%s</comment>: already %s.', $jobKey, $job->getStatus()));
                    ++$skipped;
                    continue;
                }
            }
            if (!$this->dispatcher->dispatchKey($jobKey)) {
                $io->error(sprintf('Failed to dispatch advance for %s (queue down?).', $jobKey));
                ++$failed;
                continue;
            }
            $io->writeln(sprintf('Re-armed <info>%s</info> (%s).', $jobKey, $job->getStatus()));
            ++$dispatched;
        }

        $io->success(sprintf(
            'Dispatched %d, skipped %d, failed %d.',
            $dispatched,
            $skipped,
            $failed,
        ));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
