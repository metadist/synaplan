<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Chat\StuckChatReaper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Moves stuck chat messages and files to a terminal error state.
 *
 * The request that entered processing/extracting is the fast path. This
 * command is the backstop after a worker restart or a lost Messenger
 * message (issue #1913). Safe to run on every scheduler tick — the lock
 * means only one node executes it.
 */
#[AsCommand(
    name: 'app:chat:reap-stuck',
    description: 'Mark stale processing/queued messages and extracting/vectorizing files as error'
)]
final class ReapStuckChatCommand extends Command
{
    public function __construct(
        private readonly StuckChatReaper $reaper,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $lock = $this->lockFactory->createLock('chat-stuck-reaper', 120);
        if (!$lock->acquire()) {
            $io->note('Previous stuck-chat reaper run is still active. Skipping.');

            return Command::SUCCESS;
        }

        try {
            $reaped = $this->reaper->reap();
            $total = $reaped['messages'] + $reaped['files'];
            if ($total > 0) {
                $io->success(sprintf(
                    'Marked %d stuck message(s) and %d stuck file(s) as error.',
                    $reaped['messages'],
                    $reaped['files'],
                ));
            } else {
                $io->writeln('No stuck chat messages or files to reap.');
            }
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }
}
