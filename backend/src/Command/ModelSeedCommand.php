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
            "Reconciles BMODELS with App\\Model\\ModelCatalog.\n".
            "In dev/test, also adds mock TestProvider models with negative IDs.\n\n".
            "Per row the seeder either INSERTs (new model), UPDATEs (catalog code\n".
            "changed and the row was untouched), SKIPs (already in sync) or\n".
            "PRESERVEs (admin edited the row via the /config/ai-models UI — manual\n".
            "changes are detected via a content fingerprint stored in BJSON and are\n".
            "never overwritten by container restarts).\n\n".
            'Safe to run on every deploy.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->seeder->seed();
        $io->success(sprintf(
            'Models reconciled: inserted=%d, updated=%d, skipped=%d, preserved=%d.',
            $result->inserted,
            $result->updated,
            $result->skipped,
            $result->preserved,
        ));

        return Command::SUCCESS;
    }
}
