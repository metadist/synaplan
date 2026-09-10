<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Tool\ApprovalExpiryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: 'app:approvals:expire',
    description: 'Expire pending tool approvals past their deadline'
)]
final class ExpireApprovalsCommand extends Command
{
    public function __construct(
        private readonly ApprovalExpiryService $expiry,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $lock = $this->lockFactory->createLock('approvals-expire', 120);
        if (!$lock->acquire()) {
            $io->note('A previous expiry sweep is still running.');

            return Command::SUCCESS;
        }

        try {
            $count = $this->expiry->sweep(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $io->writeln(sprintf('Expired %d pending approvals.', $count));
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }
}
