<?php

declare(strict_types=1);

namespace App\Command;

use App\AI\Credential\ChatReadinessService;
use App\AI\Credential\ProviderDefaultsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI twin of the admin "apply recommended defaults" action — used by the
 * guided install script instead of hand-written UPDATE statements against
 * BCONFIG (which hardcoded catalog BIDs and broke on renumbering).
 *
 * Two modes:
 *   php bin/console app:provider:apply-defaults groq     # explicit provider
 *   php bin/console app:provider:apply-defaults --auto   # repair a keyless default
 *
 * `--auto` runs at container start (see _docker/backend/docker-entrypoint.sh) so
 * a fresh install whose seeded default points at a provider without a key gets a
 * working default before the first request — instead of repairing it as a side
 * effect of a GET.
 */
#[AsCommand(
    name: 'app:provider:apply-defaults',
    description: 'Set the recommended global default models for an AI provider (catalog-key based, no raw BIDs)',
)]
final class ApplyProviderDefaultsCommand extends Command
{
    public function __construct(
        private readonly ProviderDefaultsService $defaults,
        private readonly ChatReadinessService $chatReadiness,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('provider', InputArgument::OPTIONAL, 'Provider name (e.g. groq, openai, anthropic, google, mistral, trustedtokens, huggingface, xai)');
        $this->addOption('auto', null, InputOption::VALUE_NONE, 'Pick the best available provider automatically, but only when the current default chat provider is a cloud provider without a usable key');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Apply the named provider even though it has no usable key yet');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $provider = $input->getArgument('provider');
        $auto = (bool) $input->getOption('auto');

        if ($auto && null !== $provider) {
            $io->error('Use either a provider name or --auto, not both.');

            return Command::INVALID;
        }

        try {
            return $auto ? $this->runAuto($io) : $this->runExplicit($io, (string) $provider, (bool) $input->getOption('force'));
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            // Catalog drift (a recommended key that no longer resolves) must end
            // in a clean non-zero exit, not an uncaught stack trace.
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    private function runAuto(SymfonyStyle $io): int
    {
        $applied = $this->chatReadiness->repairDefaultsIfBroken();

        if (null === $applied) {
            $io->info('Default chat provider left unchanged (already usable, or deliberately set to a keyless provider).');

            return Command::SUCCESS;
        }

        $io->success(sprintf('Default chat provider set to "%s" — it was the first available provider with a usable key.', $applied));

        return Command::SUCCESS;
    }

    private function runExplicit(SymfonyStyle $io, string $provider, bool $force): int
    {
        $provider = strtolower(trim($provider));

        if ('' === $provider) {
            $io->error('Pass a provider name, or --auto to pick one automatically.');

            return Command::INVALID;
        }

        if (!ProviderDefaultsService::supports($provider)) {
            $io->error(sprintf('No recommended defaults for provider "%s".', $provider));

            return Command::INVALID;
        }

        if (!($this->chatReadiness->providerAvailability(fresh: true)[$provider] ?? false) && !$force) {
            $io->error(sprintf(
                'Provider "%s" has no usable key (or is unreachable), so making it the default would break chat. Add a key first, or pass --force.',
                $provider,
            ));

            return Command::FAILURE;
        }

        $applied = $this->defaults->applyGlobalDefaults($provider);
        $this->chatReadiness->invalidate();

        $io->success(sprintf(
            'Applied %s defaults for: %s. Default chat provider is now "%s".',
            $provider,
            implode(', ', array_keys($applied)),
            $provider,
        ));

        return Command::SUCCESS;
    }
}
