<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\ModelCatalog;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:model:list',
    description: 'List all available AI models from the catalog',
)]
class ModelListCommand extends Command
{
    public function __construct(
        private Connection $connection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Get BIDs of models currently in the database
        $enabledBids = $this->connection->fetchFirstColumn('SELECT BID FROM BMODELS');
        $enabledBids = array_map('intval', $enabledBids);

        $rows = [];
        foreach (ModelCatalog::all() as $model) {
            $key = strtolower($model['service']).':'.strtolower(str_replace(':', '-', $model['providerId']));
            $enabled = in_array($model['id'], $enabledBids, true);

            $rows[] = [
                $enabled ? '<info>yes</info>' : 'no',
                $key,
                $model['tag'],
                $model['name'],
            ];
        }

        $io->table(['Active', 'Key', 'Tag', 'Name'], $rows);

        return Command::SUCCESS;
    }
}
