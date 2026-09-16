<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SavedTask\SavedTaskTickService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: 'app:saved-tasks:tick',
    description: 'Claim and run due Saved Task schedules (self-locking)'
)]
final class SavedTasksTickCommand extends Command
{
    public function __construct(
        private readonly SavedTaskTickService $tick,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $lock = $this->lockFactory->createLock('saved-tasks-tick', 120);
        if (!$lock->acquire()) {
            $io->note('Previous Saved Tasks tick is still active. Skipping.');

            return Command::SUCCESS;
        }

        try {
            if (!$this->tick->isGloballyEnabled()) {
                $io->writeln('Saved Tasks are globally off. Nothing to do.');

                return Command::SUCCESS;
            }

            $result = $this->tick->tick(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $io->writeln(sprintf(
                'Claimed %d, completed %d, failed %d.',
                $result['claimed'],
                $result['ran'],
                $result['failed'],
            ));
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }
}
