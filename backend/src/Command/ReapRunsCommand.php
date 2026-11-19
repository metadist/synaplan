<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Chat\Run\StreamRedis;
use App\Service\Run\RunReaper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Closes chat runs whose pump is gone or that nobody claimed, and recovers
 * kernel runs whose worker disappeared.
 *
 * The scheduler tick calls this, and each pump calls the same reaper when
 * it starts and while it waits. The state checks make a second caller a no-op.
 */
#[AsCommand(name: 'app:runs:reap', description: 'Fail chat runs the stream pump no longer owns and recover kernel runs')]
final class ReapRunsCommand extends Command
{
    /**
     * @param iterable<RunReaper> $kernelReapers one per kind of kernel run, tagged `app.run_reaper`
     */
    public function __construct(
        private readonly StreamRedis $redis,
        #[AutowireIterator('app.run_reaper')]
        private readonly iterable $kernelReapers = [],
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $failed = $this->redis->reap(time());
        $output->writeln('Failed '.$failed.' run'.(1 === $failed ? '' : 's').'.');

        foreach ($this->kernelReapers as $reaper) {
            $counts = $reaper->reap();
            $output->writeln(sprintf(
                'Kernel runs: %d redispatched, %d interrupted, %d moved on after waiting.',
                $counts['redispatched'],
                $counts['interrupted'],
                $counts['waited'],
            ));
        }

        return Command::SUCCESS;
    }
}
