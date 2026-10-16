<?php

declare(strict_types=1);

namespace App\Command;

use App\Seed\SeederInterface;
use App\Seed\SeedResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

#[AsCommand(
    name: 'app:seed',
    description: 'Run all idempotent catalog/config seeders (models, prompts, defaults, rate limits, demo widget)',
)]
final class SeedAllCommand extends Command
{
    /**
     * @param iterable<SeederInterface> $seeders in dependency order (tag priority)
     */
    public function __construct(
        #[AutowireIterator(SeederInterface::TAG)]
        private readonly iterable $seeders,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $lines = [];
        foreach ($this->seeders as $seeder) {
            $lines[] = sprintf('  %2d. %-22s %s', count($lines) + 1, $seeder->label(), $seeder->description());
        }

        $this->setHelp(
            "Convenience aggregator that runs every idempotent seed step in dependency order.\n\n"
            .implode("\n", $lines)."\n\n"
            ."All steps are idempotent and safe to run on every deploy. The demo-widget step is a no-op in prod.\n"
            .'A failing step does not stop the others; the command then exits non-zero and names the failed steps.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Synaplan seed: idempotent catalog/config bootstrap');

        $rows = [];
        $failed = [];
        $total = 0;
        foreach ($this->seeders as $seeder) {
            ++$total;
            $label = $seeder->label();
            try {
                $result = $this->runStep($io, $label, $seeder);
            } catch (\Throwable $e) {
                $failed[] = $label;
                $this->logger->error('Seed step failed', ['step' => $label, 'exception' => $e]);
                $io->error(sprintf('Seed step "%s" failed: %s', $label, $e->getMessage()));
                $rows[] = [$label, 'failed', '-', '-', '-'];
                continue;
            }

            $rows[] = [
                $label,
                (string) $result->inserted,
                (string) $result->updated,
                (string) $result->skipped,
                (string) $result->preserved,
            ];
        }

        $io->table(['Step', 'Inserted', 'Updated', 'Skipped', 'Preserved'], $rows);

        if ([] !== $failed) {
            $io->error(sprintf(
                '%d of %d seed steps failed: %s. All other steps completed.',
                count($failed),
                $total,
                implode(', ', $failed),
            ));

            return Command::FAILURE;
        }

        $io->success('All seed steps completed.');

        return Command::SUCCESS;
    }

    private function runStep(SymfonyStyle $io, string $label, SeederInterface $seeder): SeedResult
    {
        $io->section("Seeding: $label");
        $result = $seeder->seed();
        $io->writeln(sprintf(
            '  → inserted=%d, updated=%d, skipped=%d, preserved=%d',
            $result->inserted,
            $result->updated,
            $result->skipped,
            $result->preserved,
        ));

        return $result;
    }
}
