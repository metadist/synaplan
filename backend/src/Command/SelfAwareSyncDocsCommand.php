<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\SyncPlatformDocsMessage;
use App\Service\SelfAware\Docs\PlatformDocsSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:selfaware:sync-docs',
    description: 'Refresh the owner-0 SYSTEM:synaplan documentation corpus from the docs manifest',
)]
final class SelfAwareSyncDocsCommand extends Command
{
    public function __construct(
        private readonly PlatformDocsSyncService $syncService,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Re-vectorize every page, ignoring hashes')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the diff without writing vectors or state')
            ->addOption('async', null, InputOption::VALUE_NONE, 'Dispatch SyncPlatformDocsMessage instead of running inline');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');

        if ((bool) $input->getOption('async')) {
            $this->messageBus->dispatch(new SyncPlatformDocsMessage(force: $force));
            $io->success('Queued documentation sync on async_index.');

            return Command::SUCCESS;
        }

        $result = $this->syncService->sync(force: $force, dryRun: $dryRun);

        if ('skipped' === $result->status) {
            $io->writeln('skipped: '.$result->reason);

            return Command::SUCCESS;
        }

        $table = [];
        foreach ($result->rows as $row) {
            $table[] = [$row['slug'], $row['action'], (string) $row['chunks'], $row['message']];
        }
        if ([] !== $table) {
            $io->table(['Slug', 'Action', 'Chunks', 'Message'], $table);
        }

        $io->writeln(sprintf(
            'changed=%d unchanged=%d removed=%d failed=%d',
            $result->changed,
            $result->unchanged,
            $result->removed,
            $result->failed,
        ));

        if ($result->isFailed()) {
            $io->error('Documentation sync failed: '.$result->reason);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
