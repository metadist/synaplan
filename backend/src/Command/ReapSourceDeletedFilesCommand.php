<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\FileRepository;
use App\Service\Document\Persist\DocumentRevisionService;
use App\Service\File\CloudSourceEvents;
use App\Service\File\FileStorageService;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Removes cloud copies whose source was deleted more than 7 days ago.
 */
#[AsCommand(name: 'app:cloud-files:reap', description: 'Delete cloud copies whose source has been gone for 7 days')]
final class ReapSourceDeletedFilesCommand extends Command
{
    public function __construct(
        private readonly FileRepository $files,
        private readonly FileStorageService $storage,
        private readonly VectorStorageFacade $vectors,
        private readonly DocumentRevisionService $revisions,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('now', null, InputOption::VALUE_REQUIRED, 'Unix time to treat as now, for tests');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = $input->getOption('now');
        $now = is_string($now) && ctype_digit($now) ? (int) $now : time();
        $expired = [];
        $filters = $this->entityManager->getFilters();
        $wasOn = $filters->isEnabled('source_deleted');
        if ($wasOn) {
            $filters->disable('source_deleted');
        }
        try {
            $expired = $this->files->findSourceDeletedBefore($now - CloudSourceEvents::GRACE_SECONDS);
            foreach ($expired as $file) {
                $this->vectors->deleteByFile($file->getUserId(), (int) $file->getId());
                $this->revisions->deleteForFile($file);
                $path = $file->getFilePath();
                if ('' !== $path) {
                    $this->storage->deleteFile($path);
                }
                $this->files->delete($file);
            }
        } finally {
            if ($wasOn) {
                $filters->enable('source_deleted');
            }
        }
        $output->writeln(sprintf('Reaped %d cloud file(s).', count($expired)));

        return Command::SUCCESS;
    }
}
