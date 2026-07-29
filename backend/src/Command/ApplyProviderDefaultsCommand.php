<?php

declare(strict_types=1);

namespace App\Command;

use App\AI\Credential\ProviderDefaultsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI twin of the admin "apply recommended defaults" action — used by the
 * guided install script instead of hand-written UPDATE statements against
 * BCONFIG (which hardcoded catalog BIDs and broke on renumbering).
 *
 * Example: php bin/console app:provider:apply-defaults groq
 */
#[AsCommand(
    name: 'app:provider:apply-defaults',
    description: 'Set the recommended global default models for an AI provider (catalog-key based, no raw BIDs)',
)]
final class ApplyProviderDefaultsCommand extends Command
{
    public function __construct(
        private readonly ProviderDefaultsService $defaults,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('provider', InputArgument::REQUIRED, 'Provider name (e.g. groq, openai, anthropic, google, mistral, trustedtokens, huggingface, xai)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $provider = strtolower((string) $input->getArgument('provider'));

        if (!ProviderDefaultsService::supports($provider)) {
            $io->error(sprintf('No recommended defaults for provider "%s".', $provider));

            return Command::INVALID;
        }

        $applied = $this->defaults->applyGlobalDefaults($provider);

        $io->success(sprintf(
            'Applied %s defaults for: %s. Default chat provider is now "%s".',
            $provider,
            implode(', ', array_keys($applied)),
            $provider,
        ));

        return Command::SUCCESS;
    }
}
