<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\SyncPlatformDocsMessage;
use App\Service\Update\UpdateStatusService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Refreshes the stored release-notice status (detection only — this command
 * never changes the installation).
 *
 * Run once per day by the scheduler container. A network failure or an
 * unreadable manifest is an EXPECTED outcome, recorded in BCONFIG and reported
 * as success: the scheduler log must not fill up with stack traces because
 * GitHub was briefly unreachable. Only a genuinely unexpected error exits
 * non-zero.
 */
#[AsCommand(
    name: 'app:updates:check',
    description: 'Check whether a newer Synaplan release exists and store the result (no installation change)'
)]
final class CheckUpdatesCommand extends Command
{
    public function __construct(
        private readonly UpdateStatusService $updateStatusService,
        private readonly ?MessageBusInterface $messageBus = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'force',
            null,
            InputOption::VALUE_NONE,
            'Bypass the cached manifest and fetch it again'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $previousLatest = $this->updateStatusService->getStatus()['latestVersion'];
            $status = $this->updateStatusService->refresh(force: (bool) $input->getOption('force'));
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Update check failed unexpectedly: %s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        $newLatest = $status['latestVersion'] ?? null;
        if (null !== $this->messageBus && null !== $newLatest && $newLatest !== $previousLatest) {
            $this->messageBus->dispatch(new SyncPlatformDocsMessage());
        }

        $output->writeln($this->summarize($status));

        return Command::SUCCESS;
    }

    /**
     * @param array{currentVersion: string, latestVersion: string|null, updateAvailable: bool, severity: string, lastError: string|null, checkEnabled: bool} $status
     */
    private function summarize(array $status): string
    {
        if (!$status['checkEnabled']) {
            return 'Update check is disabled; nothing was fetched.';
        }

        if (null !== $status['lastError']) {
            return sprintf('Update check could not complete: %s', $status['lastError']);
        }

        if ($status['updateAvailable']) {
            return sprintf(
                'Update available: %s (current %s, severity %s).',
                (string) $status['latestVersion'],
                $status['currentVersion'],
                $status['severity'],
            );
        }

        return sprintf(
            'No update available (current %s, latest known %s).',
            $status['currentVersion'],
            $status['latestVersion'] ?? 'unknown',
        );
    }
}
