<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Mcp\Notion\NotionWatchService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:notion-watches:poll', description: 'Check followed Notion pages that are due and act on a change')]
final class PollNotionWatchesCommand extends Command
{
    private const BATCH = 50;

    public function __construct(private readonly NotionWatchService $watches)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $checked = $this->watches->pollDue(time(), self::BATCH);
        $output->writeln(sprintf('Checked %d followed Notion page(s).', $checked));

        return Command::SUCCESS;
    }
}
