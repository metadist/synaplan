<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Security\SecretReencryptor;
use App\Service\Security\SecretReencryptTally;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rewrites the secrets this installation stores (provider keys, mailbox
 * passwords, connector tokens) in the current format, each bound to its row's
 * owner. Safe to run while the application serves requests, and to run again.
 */
#[AsCommand(
    name: 'app:secrets:reencrypt',
    description: 'Rewrite stored secrets in the current encryption format',
)]
final class SecretsReencryptCommand extends Command
{
    private const OPTION_DRY_RUN = 'dry-run';

    public function __construct(
        private readonly SecretReencryptor $reencryptor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(self::OPTION_DRY_RUN, null, InputOption::VALUE_NONE, 'Count what would be rewritten and change nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption(self::OPTION_DRY_RUN);

        $tallies = $this->reencryptor->run(!$dryRun);

        $io->table(
            ['Stored in', 'Current', $dryRun ? 'Would rewrite' : 'Rewritten', 'Unreadable', 'Saved meanwhile'],
            array_map(static fn (SecretReencryptTally $tally): array => [
                $tally->location,
                $tally->current,
                $tally->rewritten,
                count($tally->unreadableIds),
                $tally->changed,
            ], $tallies),
        );

        $unreadable = array_filter($tallies, static fn (SecretReencryptTally $tally): bool => [] !== $tally->unreadableIds);
        foreach ($unreadable as $tally) {
            $io->warning(sprintf(
                '%s: this APP_SECRET does not open row %s. Left unchanged; enter the value again in the application.',
                $tally->location,
                implode(', ', $tally->unreadableIds),
            ));
        }

        $rewritten = array_sum(array_map(static fn (SecretReencryptTally $tally): int => $tally->rewritten, $tallies));
        $io->success($dryRun
            ? sprintf('%d stored secret(s) would be rewritten. Nothing was changed.', $rewritten)
            : sprintf('%d stored secret(s) rewritten.', $rewritten));

        return Command::SUCCESS;
    }
}
