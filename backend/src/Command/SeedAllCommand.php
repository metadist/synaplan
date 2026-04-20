<?php

declare(strict_types=1);

namespace App\Command;

use App\Seed\DefaultModelConfigSeeder;
use App\Seed\DemoWidgetConfigSeeder;
use App\Seed\ModelSeeder;
use App\Seed\PromptSeeder;
use App\Seed\RateLimitConfigSeeder;
use App\Seed\SeedResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Run every idempotent app:*:seed step in dependency order.
 *
 * Order:
 *   1. models      (BMODELS — referenced by DEFAULTMODEL config)
 *   2. prompts     (BPROMPTS)
 *   3. defaults    (BCONFIG: DEFAULTMODEL → references model IDs from step 1)
 *   4. ratelimit   (BCONFIG: SYSTEM_FLAGS, RATELIMITS_*)
 *   5. demo-widget (BCONFIG: example widget for ownerId=2 — dev/test only, no-op in prod)
 *
 * Wired into the Docker entrypoint after `doctrine:migrations:migrate`, so it runs
 * on every container startup in dev AND prod.
 */
#[AsCommand(
    name: 'app:seed',
    description: 'Run all idempotent catalog/config seeders (models, prompts, defaults, rate limits, demo widget)',
)]
final class SeedAllCommand extends Command
{
    public function __construct(
        private readonly ModelSeeder $modelSeeder,
        private readonly PromptSeeder $promptSeeder,
        private readonly DefaultModelConfigSeeder $defaultModelConfigSeeder,
        private readonly RateLimitConfigSeeder $rateLimitConfigSeeder,
        private readonly DemoWidgetConfigSeeder $demoWidgetConfigSeeder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(
            "Convenience aggregator that runs every idempotent seed step in dependency order.\n\n".
            "  1. app:model:seed              (BMODELS)\n".
            "  2. app:prompt:seed             (BPROMPTS, ownerId=0)\n".
            "  3. app:config:seed-defaults    (BCONFIG, group=DEFAULTMODEL/ai)\n".
            "  4. app:ratelimit:seed-defaults (BCONFIG, group=RATELIMITS_*/SYSTEM_FLAGS)\n".
            "  5. demo widget config          (BCONFIG, group=widget_1, ownerId=2 — dev/test only)\n\n".
            'All steps are idempotent and safe to run on every deploy. The demo-widget step is a no-op in prod.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Synaplan seed: idempotent catalog/config bootstrap');

        $results = [
            $this->runStep($io, 'models', fn (): SeedResult => $this->modelSeeder->seed()),
            $this->runStep($io, 'prompts', fn (): SeedResult => $this->promptSeeder->seed()),
            $this->runStep($io, 'defaults', fn (): SeedResult => $this->defaultModelConfigSeeder->seed()),
            $this->runStep($io, 'rate-limits', fn (): SeedResult => $this->rateLimitConfigSeeder->seed()),
            $this->runStep($io, 'demo-widget', fn (): SeedResult => $this->demoWidgetConfigSeeder->seed()),
        ];

        $io->table(
            ['Step', 'Inserted', 'Updated', 'Skipped'],
            array_map(
                static fn (SeedResult $r): array => [$r->label, (string) $r->inserted, (string) $r->updated, (string) $r->skipped],
                $results
            )
        );

        $io->success('All seed steps completed.');

        return Command::SUCCESS;
    }

    /**
     * @param callable(): SeedResult $step
     */
    private function runStep(SymfonyStyle $io, string $label, callable $step): SeedResult
    {
        $io->section("Seeding: $label");
        $result = $step();
        $io->writeln(sprintf(
            '  → inserted=%d, updated=%d, skipped=%d',
            $result->inserted,
            $result->updated,
            $result->skipped
        ));

        return $result;
    }
}
