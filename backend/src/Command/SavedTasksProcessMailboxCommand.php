<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\InboundEmailHandlerRepository;
use App\Repository\SavedTaskRepository;
use App\Service\SavedTask\InboundEmailFilter;
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
            ->addOption('message', 'm', InputOption::VALUE_REQUIRED, 'Message text to run with', 'Look into my connected mailbox and extract meeting requests.')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Envelope From for the filter', '')
            ->addOption('subject', null, InputOption::VALUE_REQUIRED, 'Subject for the filter', '')
            ->addOption('body', null, InputOption::VALUE_REQUIRED, 'Plain body for the filter', '');
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
        $from = (string) $input->getOption('from');
        $subject = (string) $input->getOption('subject');
        $body = (string) $input->getOption('body');
        $hasMailMeta = '' !== $from || '' !== $subject || '' !== $body;
        $ran = 0;
        foreach ($this->tasks->findEnabledInboundEmailTasks($ownerId, $accountId) as $task) {
            $id = $task->getId();
            if (null === $id) {
                continue;
            }
            $filter = $task->getTriggerConfig()['filter'] ?? null;
            if ($hasMailMeta && !InboundEmailFilter::matches(is_array($filter) ? $filter : null, $from, $subject, $body)) {
                continue;
            }
            $this->runner->run($ownerId, $id, $message, 'inbound_email');
            ++$ran;
        }

        $io->writeln(sprintf('Ran %d Saved Task(s) for mailbox %d.', $ran, $accountId));

        return Command::SUCCESS;
    }
}
