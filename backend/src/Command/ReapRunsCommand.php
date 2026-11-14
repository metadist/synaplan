<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Chat\Run\StreamRedis;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Closes runs whose pump is gone or that nobody claimed.
 *
 * The scheduler tick calls this, and each pump calls the same reaper when
 * it starts and while it waits. The state check makes a second caller a no-op.
 */
#[AsCommand(name: 'app:runs:reap', description: 'Fail chat runs the stream pump no longer owns')]
final class ReapRunsCommand extends Command
{
    public function __construct(
        private readonly StreamRedis $redis,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $failed = $this->redis->reap(time());
        $output->writeln('Failed '.$failed.' run'.(1 === $failed ? '' : 's').'.');

        return Command::SUCCESS;
    }
}
