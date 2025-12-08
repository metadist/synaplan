<?php

namespace App\Command;

use App\Service\Message\SearchQueryGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-search-query',
    description: 'Test search query generation from user questions',
)]
class TestSearchQueryCommand extends Command
{
    public function __construct(
        private SearchQueryGenerator $searchQueryGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('question', InputArgument::REQUIRED, 'User question to convert to search query')
            ->addArgument('userId', InputArgument::OPTIONAL, 'User ID (optional)', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $question = $input->getArgument('question');
        $userId = $input->getArgument('userId') ? (int) $input->getArgument('userId') : null;

        $io->title('Search Query Generator Test');
        $io->section('Input');
        $io->text("Question: {$question}");
        if ($userId) {
            $io->text("User ID: {$userId}");
        }

        $io->section('Processing');
        $startTime = microtime(true);

        try {
            $searchQuery = $this->searchQueryGenerator->generate($question, $userId);
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $io->section('Result');
            $io->success("Generated search query in {$duration}ms:");
            $io->writeln("  → {$searchQuery}");

            $io->section('Analysis');
            $io->text([
                'Original length: '.strlen($question).' characters',
                'Generated length: '.strlen($searchQuery).' characters',
                'Reduction: '.round((1 - strlen($searchQuery) / strlen($question)) * 100, 1).'%',
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Failed to generate search query: '.$e->getMessage());
            $io->text('Exception: '.get_class($e));

            return Command::FAILURE;
        }
    }
}
