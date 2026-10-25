<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Research\Run\Lead\LeadConformance;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs the tool-mode suite on test doubles. The catalog key is recorded and
 * not called, so this command never spends a provider key.
 */
#[AsCommand(
    name: 'app:research:conformance',
    description: 'Run the research tool-mode suite on test doubles.',
)]
final class ResearchLeadConformanceCommand extends Command
{
    public function __construct(private readonly LeadConformance $suite)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('key', InputArgument::REQUIRED, 'Catalog key service:providerId:tag. The suite does not call this model.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $key = (string) $input->getArgument('key');
        $results = $this->suite->results();
        $failed = [];
        foreach ($results as $name => $failure) {
            if (null !== $failure) {
                $failed[] = $name.': '.$failure;
            }
        }
        if ([] !== $failed || LeadConformance::CASES !== count($results)) {
            $io->error([] === $failed ? ['The suite did not run '.LeadConformance::CASES.' turns.'] : $failed);

            return Command::FAILURE;
        }
        $io->success($key.' passed '.LeadConformance::CASES.' scripted turns. No provider was called.');

        return Command::SUCCESS;
    }
}
