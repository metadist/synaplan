<?php

declare(strict_types=1);

namespace App\Command;

use App\Seed\ModelSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:model:seed',
    description: 'Upsert the built-in AI model catalog into BMODELS (idempotent)',
)]
final class ModelSeedCommand extends Command
{
    public function __construct(private readonly ModelSeeder $seeder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(
            "Upserts every model from App\\Model\\ModelCatalog into BMODELS.\n".
            "In dev/test, also upserts mock TestProvider models with negative IDs.\n\n".
            'Safe to run on every deploy — uses INSERT ... ON DUPLICATE KEY UPDATE.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->seeder->seed();
        $io->success(sprintf('Upserted %d models.', $result->inserted));

        return Command::SUCCESS;
    }
}
