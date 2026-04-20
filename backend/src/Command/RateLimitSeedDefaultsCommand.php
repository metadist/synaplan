<?php

declare(strict_types=1);

namespace App\Command;

use App\Seed\RateLimitConfigSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ratelimit:seed-defaults',
    description: 'Insert default rate-limit configuration into BCONFIG (insert-if-missing)',
)]
final class RateLimitSeedDefaultsCommand extends Command
{
    public function __construct(private readonly RateLimitConfigSeeder $seeder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(
            "Seeds smart rate limiting flags + per-plan limits (ANONYMOUS, NEW, PRO, TEAM, BUSINESS)\n".
            'into BCONFIG (ownerId=0). Operator overrides are NEVER overwritten.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->seeder->seed();
        $io->success(sprintf(
            'Rate limits: %d inserted, %d already present.',
            $result->inserted,
            $result->skipped
        ));

        return Command::SUCCESS;
    }
}
