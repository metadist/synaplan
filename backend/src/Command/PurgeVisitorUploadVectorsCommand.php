<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Widget\VisitorUploadVectorPurge;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-off clean-up after the update that stopped indexing widget visitors'
 * uploads: removes the chunks indexed before it. Safe to run again.
 *
 *   docker compose exec -T backend php bin/console app:widget:purge-visitor-upload-vectors
 */
#[AsCommand(
    name: 'app:widget:purge-visitor-upload-vectors',
    description: 'Delete vector chunks of files uploaded by widget visitors (the files stay)'
)]
final class PurgeVisitorUploadVectorsCommand extends Command
{
    public function __construct(private readonly VisitorUploadVectorPurge $purge)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count the chunks without deleting them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $summary = $this->purge->run($dryRun);

        $io->success(sprintf(
            '%s %d chunk(s) of %d visitor upload(s).',
            $dryRun ? 'Would delete' : 'Deleted',
            $summary['chunks'],
            $summary['files'],
        ));
        if ($summary['ownerless'] > 0) {
            $io->note(sprintf(
                '%d upload(s) belong to a widget or session that no longer exists. Their chunks, if any, were left in place; search does not return them.',
                $summary['ownerless'],
            ));
        }

        return Command::SUCCESS;
    }
}
