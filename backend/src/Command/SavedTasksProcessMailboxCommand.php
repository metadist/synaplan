<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\InboundEmailHandlerRepository;
use App\Repository\SavedTaskRepository;
use App\Service\SavedTask\SavedTaskConfig;
use App\Service\SavedTask\SavedTaskRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:saved-tasks:process-mailbox',
    description: 'Run inbound-email Saved Tasks for one mailbox (does not replace mail handlers)'
)]
final class SavedTasksProcessMailboxCommand extends Command
{
    public function __construct(
        private readonly SavedTaskConfig $config,
        private readonly InboundEmailHandlerRepository $handlers,
        private readonly SavedTaskRepository $tasks,
        private readonly SavedTaskRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('accountId', InputArgument::REQUIRED, 'Inbound email handler id')
            ->addOption('message', 'm', InputOption::VALUE_REQUIRED, 'Message text to run with', 'Look into my connected mailbox and extract meeting requests.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $accountId = (int) $input->getArgument('accountId');
        $handler = $this->handlers->find($accountId);
        if (null === $handler) {
            $io->error('Mailbox was not found');

            return Command::FAILURE;
        }

        $ownerId = $handler->getUserId();
        if (!$this->config->isEnabled($ownerId)) {
            $io->writeln('Saved Tasks are off for this user.');

            return Command::SUCCESS;
        }

        $message = (string) $input->getOption('message');
        $ran = 0;
        foreach ($this->tasks->findEnabledInboundEmailTasks($ownerId, $accountId) as $task) {
            $id = $task->getId();
            if (null === $id) {
                continue;
            }
            $this->runner->run($ownerId, $id, $message, 'inbound_email');
            ++$ran;
        }

        $io->writeln(sprintf('Ran %d Saved Task(s) for mailbox %d.', $ran, $accountId));

        return Command::SUCCESS;
    }
}
