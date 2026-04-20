<?php

declare(strict_types=1);

namespace App\Command;

use App\Seed\DefaultModelConfigSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:config:seed-defaults',
    description: 'Insert global DEFAULTMODEL/ai config defaults into BCONFIG (insert-if-missing)',
)]
final class ConfigSeedDefaultsCommand extends Command
{
    public function __construct(private readonly DefaultModelConfigSeeder $seeder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(
            "Seeds initial DEFAULTMODEL bindings (CHAT, TOOLS, TEXT2PIC, ...) plus the\n".
            "ai.default_chat_provider flag into BCONFIG (ownerId=0). Uses insert-if-missing,\n".
            "so operator overrides are NEVER overwritten.\n\n".
            'In the test env, defaults are routed at TestProvider models (negative IDs from ModelSeeder).'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->seeder->seed();
        $io->success(sprintf(
            'Default model config: %d inserted, %d already present.',
            $result->inserted,
            $result->skipped
        ));

        return Command::SUCCESS;
    }
}
