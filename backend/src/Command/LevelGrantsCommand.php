<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\Iam\LevelGrantService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reconstructs level grants for accounts that predate the table.
 *
 * Dry run by default. It lists only the accounts whose reconstruction is
 * more than "the active subscription owns the level", with the email, the
 * current level and the grants it would write. `--apply` writes them.
 * Running it twice changes nothing.
 */
#[AsCommand(
    name: 'app:iam:level-grants',
    description: 'Reconstruct level grants for existing accounts. Dry run unless --apply is passed.',
)]
final class LevelGrantsCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly LevelGrantService $grants,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Write the grants. Without this flag nothing is stored.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $afterId = 0;
        $interesting = 0;
        $written = 0;

        do {
            $batch = $this->users->findAfterId($afterId, 200);
            foreach ($batch as $user) {
                $afterId = (int) $user->getId();
                if ($this->grants->hasGrants($user)) {
                    continue;
                }
                $plan = $this->grants->plan($user);
                if ($plan->interesting()) {
                    ++$interesting;
                    $io->writeln(sprintf(
                        '%s  %s  %s',
                        (string) $user->getMail(),
                        $user->getUserLevel(),
                        $this->describe($plan->bySource()),
                    ));
                }
                if ($apply && $this->grants->reconstruct($user)) {
                    ++$written;
                }
            }
        } while ([] !== $batch);

        $io->success($apply
            ? sprintf('Wrote grants for %d account(s). %d account(s) were not a plain subscription.', $written, $interesting)
            : sprintf('Dry run. %d account(s) are not a plain subscription. Pass --apply to write.', $interesting));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, array{level: string}> $rows
     */
    private function describe(array $rows): string
    {
        $parts = [];
        foreach ($rows as $source => $row) {
            $parts[] = $source.' '.$row['level'];
        }

        return [] === $parts ? 'none' : implode(', ', $parts);
    }
}
