<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Message\SynapseIndexer;
use App\Service\VectorSearch\QdrantClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'synapse:index',
    description: 'Index topic embeddings into Qdrant for Synapse Routing',
)]
class SynapseIndexCommand extends Command
{
    public function __construct(
        private readonly SynapseIndexer $indexer,
        private readonly QdrantClientInterface $qdrantClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user', 'u', InputOption::VALUE_OPTIONAL, 'Index topics for a specific user ID (also re-indexes system topics)')
            ->addOption('status', 's', InputOption::VALUE_NONE, 'Show indexing status without performing any indexing')
            ->setHelp(
                "Embeds all topic descriptions and stores them in the Qdrant\n".
                "synapse_topics collection for fast embedding-based routing.\n\n".
                "Run this once after deployment or whenever topics are changed\n".
                "directly in the database (API changes auto-index).\n\n".
                "Examples:\n".
                "  synapse:index              Index all system topics\n".
                "  synapse:index --user=42    Index system + user 42's topics\n".
                "  synapse:index --status     Show collection stats\n"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('status')) {
            return $this->showStatus($io);
        }

        $userId = $input->getOption('user');
        $userId = null !== $userId ? (int) $userId : null;

        $modelInfo = $this->indexer->getEmbeddingModelInfo();
        $io->section('Synapse Routing — Topic Indexer');
        $io->text(sprintf(
            'Embedding model: %s/%s',
            $modelInfo['provider'] ?? 'auto',
            $modelInfo['model'] ?? 'default',
        ));

        if (null !== $userId) {
            $io->text(sprintf('Scope: system topics + user %d topics', $userId));
        } else {
            $io->text('Scope: system topics only');
        }

        $io->newLine();

        try {
            $count = $this->indexer->indexAllTopics($userId);
            $io->success(sprintf('Indexed %d topic(s) into synapse_topics collection.', $count));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error(sprintf('Indexing failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }
    }

    private function showStatus(SymfonyStyle $io): int
    {
        $io->section('Synapse Routing — Status');

        try {
            $collection = $this->qdrantClient->getSynapseCollection();
            $io->text(sprintf('Collection: %s', $collection));

            if (!$this->qdrantClient->isAvailable()) {
                $io->warning('Qdrant is not available.');

                return Command::FAILURE;
            }

            $modelInfo = $this->indexer->getEmbeddingModelInfo();
            $io->text(sprintf(
                'Embedding model: %s/%s',
                $modelInfo['provider'] ?? 'not configured',
                $modelInfo['model'] ?? 'not configured',
            ));

            $io->success('Qdrant is available and Synapse collection is configured.');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error(sprintf('Status check failed: %s', $e->getMessage()));

            return Command::FAILURE;
        }
    }
}
