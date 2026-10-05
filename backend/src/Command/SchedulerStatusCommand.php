<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Scheduler\ScheduledJobStatusReader;
use App\Service\Scheduler\ScheduledJobStatusReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints when scheduled jobs last ran. A watchdog treats exit 2 as "the loop
 * is not finishing tick work".
 *
 * Exit 0: the newest tick-lane finish is within --max-age.
 * Exit 2: that finish is older than --max-age, or no tick job has finished.
 * Exit 1: status could not be read; the message is on stderr.
 */
#[AsCommand(
    name: 'app:scheduler:status',
    description: 'Show when scheduled jobs last ran',
)]
final class SchedulerStatusCommand extends Command
{
    public const DEFAULT_MAX_AGE_SECONDS = ScheduledJobStatusReader::DEFAULT_MAX_AGE_SECONDS;
    public const EXIT_RUNNING = 0;
    public const EXIT_ERROR = 1;
    public const EXIT_NOT_RUNNING = 2;

    public function __construct(
        private readonly ScheduledJobStatusReader $reader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('max-age', null, InputOption::VALUE_REQUIRED, 'Seconds since the newest tick-lane finish before the scheduler counts as stale', self::DEFAULT_MAX_AGE_SECONDS)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print status as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $maxAge = $this->positiveInt($input->getOption('max-age'));
        if (null === $maxAge) {
            $this->writeError($output, '--max-age must be an integer greater than or equal to 1.');

            return self::EXIT_ERROR;
        }

        try {
            $report = $this->reader->read($maxAge);
        } catch (\Throwable $e) {
            $this->writeError($output, 'Scheduler status failed: '.$e->getMessage());

            return self::EXIT_ERROR;
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln($this->json($report));
        } else {
            $output->writeln($report->state);
            $io = new SymfonyStyle($input, $output);
            $io->table(
                ['Command', 'Lane', 'Last start', 'Last finish', 'Exit code', 'Host'],
                $this->rows($report),
            );
        }

        if (ScheduledJobStatusReader::STATE_RUNNING === $report->state) {
            return self::EXIT_RUNNING;
        }

        return self::EXIT_NOT_RUNNING;
    }

    private function positiveInt(mixed $raw): ?int
    {
        if (is_int($raw) && $raw >= 1) {
            return $raw;
        }

        if (!is_string($raw) || 1 !== preg_match('/\A[1-9][0-9]*\z/', $raw)) {
            return null;
        }

        $value = (int) $raw;
        if ((string) $value !== $raw) {
            return null;
        }

        return $value;
    }

    /**
     * @return list<list<string>>
     */
    private function rows(ScheduledJobStatusReport $report): array
    {
        $rows = [];
        foreach ($report->jobs as $job) {
            $rows[] = [
                $job->command,
                $job->lane,
                $this->formatTime($job->lastStartedAt),
                $this->formatTime($job->lastFinishedAt),
                null === $job->lastExitCode ? '-' : (string) $job->lastExitCode,
                null === $job->host || '' === $job->host ? '-' : $job->host,
            ];
        }

        return $rows;
    }

    private function formatTime(?int $timestamp): string
    {
        if (null === $timestamp) {
            return '-';
        }

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function json(ScheduledJobStatusReport $report): string
    {
        $jobs = [];
        foreach ($report->jobs as $job) {
            $jobs[] = [
                'command' => $job->command,
                'lane' => $job->lane,
                'lastStartedAt' => $job->lastStartedAt,
                'lastFinishedAt' => $job->lastFinishedAt,
                'lastExitCode' => $job->lastExitCode,
                'host' => $job->host,
            ];
        }

        return json_encode(
            ['state' => $report->state, 'jobs' => $jobs],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        );
    }

    private function writeError(OutputInterface $output, string $message): void
    {
        $target = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $target->writeln($message);
    }
}
