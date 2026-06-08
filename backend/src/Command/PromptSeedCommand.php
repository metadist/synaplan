<?php

declare(strict_types=1);

namespace App\Command;

use App\Seed\PromptSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:prompt:seed',
    description: 'Seed built-in system prompts into the database',
)]
final class PromptSeedCommand extends Command
{
    public function __construct(
        private readonly PromptSeeder $promptSeeder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(
            "Upserts all built-in system prompts (ownerId=0) into the database.\n\n".
            "New prompts are inserted, existing ones are updated with the latest text.\n".
            "User-created prompts (ownerId>0) are never touched.\n\n".
            'Idempotent and safe to run on every container start.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->promptSeeder->seed();

        $io->success(sprintf(
            'Seeded %d system prompts (%d inserted, %d updated).',
            $result->inserted + $result->updated,
            $result->inserted,
            $result->updated,
        ));

        return Command::SUCCESS;
    }
}
