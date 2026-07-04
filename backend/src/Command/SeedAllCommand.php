<?php

declare(strict_types=1);

namespace App\Command;

use App\Seed\BrandingConfigSeeder;
use App\Seed\DefaultModelConfigSeeder;
use App\Seed\DemoWidgetConfigSeeder;
use App\Seed\MarketingNewsConfigSeeder;
use App\Seed\McpConfigSeeder;
use App\Seed\MediaJobConfigSeeder;
use App\Seed\MobileConfigSeeder;
use App\Seed\ModelSeeder;
use App\Seed\MultitaskConfigSeeder;
use App\Seed\PromptSeeder;
use App\Seed\RateLimitConfigSeeder;
use App\Seed\SeedResult;
use App\Seed\SubscriptionPlanSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Run every idempotent app:*:seed step in dependency order.
 *
 * Order:
 *   1. models        (BMODELS — referenced by DEFAULTMODEL config)
 *   2. prompts       (BPROMPTS)
 *   3. defaults      (BCONFIG: DEFAULTMODEL → references model IDs from step 1)
 *   4. rate-limits   (BCONFIG: SYSTEM_FLAGS, RATELIMITS_*)
 *   5. subscriptions (BSUBSCRIPTIONS — plan cost budgets)
 *   6. multitask     (BCONFIG: MULTITASK routing flags)
 *   7. mcp           (BCONFIG: MCP outbound-client flags, ownerId=0 — default ON)
 *   8. media-jobs    (BCONFIG: MEDIA async media flag, ownerId=0 — default ON)
 *   9. branding      (BCONFIG: BRANDING white-label defaults, ownerId=0)
 *  10. mobile        (BCONFIG: MOBILE forced-update gate defaults, ownerId=0)
 *  11. marketing-news (BCONFIG: MARKETING_NEWS guest-landing flags, master switch OFF)
 *  12. demo-widget   (BCONFIG: example widget for ownerId=2 — dev/test only, no-op in prod)
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
        private readonly SubscriptionPlanSeeder $subscriptionPlanSeeder,
        private readonly MultitaskConfigSeeder $multitaskConfigSeeder,
        private readonly McpConfigSeeder $mcpConfigSeeder,
        private readonly MediaJobConfigSeeder $mediaJobConfigSeeder,
        private readonly BrandingConfigSeeder $brandingConfigSeeder,
        private readonly MobileConfigSeeder $mobileConfigSeeder,
        private readonly MarketingNewsConfigSeeder $marketingNewsConfigSeeder,
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
            "  5. subscription cost budgets   (BSUBSCRIPTIONS)\n".
            "  6. multitask routing flags     (BCONFIG, group=MULTITASK)\n".
            "  7. mcp outbound-client flags   (BCONFIG, group=MCP, ownerId=0 — default ON)\n".
            "  8. media-jobs async flag       (BCONFIG, group=MEDIA, ownerId=0 — default ON)\n".
            "  9. branding defaults           (BCONFIG, group=BRANDING, ownerId=0)\n".
            "  10. mobile gate defaults       (BCONFIG, group=MOBILE, ownerId=0)\n".
            "  11. marketing news flags       (BCONFIG, group=MARKETING_NEWS, ownerId=0 — master switch OFF)\n".
            "  12. demo widget config         (BCONFIG, group=widget_1, ownerId=2 — dev/test only)\n\n".
            'All steps are idempotent and safe to run on every deploy. The demo-widget step is a no-op in prod.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Synaplan seed: idempotent catalog/config bootstrap');

        $steps = [
            ['models',      fn (): SeedResult => $this->modelSeeder->seed()],
            ['prompts',     fn (): SeedResult => $this->promptSeeder->seed()],
            ['defaults',    fn (): SeedResult => $this->defaultModelConfigSeeder->seed()],
            ['rate-limits', fn (): SeedResult => $this->rateLimitConfigSeeder->seed()],
            ['subscriptions', fn (): SeedResult => $this->subscriptionPlanSeeder->seed()],
            ['multitask',   fn (): SeedResult => $this->multitaskConfigSeeder->seed()],
            ['mcp',         fn (): SeedResult => $this->mcpConfigSeeder->seed()],
            ['media-jobs',  fn (): SeedResult => $this->mediaJobConfigSeeder->seed()],
            ['branding',    fn (): SeedResult => $this->brandingConfigSeeder->seed()],
            ['mobile',      fn (): SeedResult => $this->mobileConfigSeeder->seed()],
            ['marketing-news', fn (): SeedResult => $this->marketingNewsConfigSeeder->seed()],
            ['demo-widget', fn (): SeedResult => $this->demoWidgetConfigSeeder->seed()],
        ];

        $rows = [];
        foreach ($steps as [$label, $callable]) {
            $result = $this->runStep($io, $label, $callable);
            $rows[] = [
                $label,
                (string) $result->inserted,
                (string) $result->updated,
                (string) $result->skipped,
                (string) $result->preserved,
            ];
        }

        $io->table(['Step', 'Inserted', 'Updated', 'Skipped', 'Preserved'], $rows);

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
            '  → inserted=%d, updated=%d, skipped=%d, preserved=%d',
            $result->inserted,
            $result->updated,
            $result->skipped,
            $result->preserved,
        ));

        return $result;
    }
}
