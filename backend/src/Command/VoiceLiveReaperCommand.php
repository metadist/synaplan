<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Voice\VoiceLiveReaper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Safety net for a gateway that died without a close callback.
 * The scheduler runs this on the tick lane.
 */
#[AsCommand(
    name: 'app:voice:reap-sessions',
    description: 'Close live voice sessions whose gateway stopped reporting usage'
)]
final class VoiceLiveReaperCommand extends Command
{
    public function __construct(
        private readonly VoiceLiveReaper $reaper,
        private readonly LockFactory $locks,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lock = $this->locks->createLock('voice-live-reaper', 30);
        if (!$lock->acquire()) {
            $output->writeln('Live voice reaper is already running.');

            return Command::SUCCESS;
        }

        try {
            $closed = $this->reaper->reap();
        } finally {
            $lock->release();
        }

        $output->writeln(sprintf('Closed %d live voice session(s).', $closed));

        return Command::SUCCESS;
    }
}
