<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\GuestChatConfig;
use App\Service\RegistrationConfig;
use App\Service\Setup\SetupConstants;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Puts this installation back into its first-run state so the setup wizard runs
 * again. Development aid only.
 *
 * Reaching the wizard requires an empty BUSER table and no SETUP.COMPLETED flag,
 * which is a state a working installation never returns to on its own. Producing
 * it by hand means a chain of DELETEs across every table that references BUSER —
 * easy to get half right, and a half-deleted user tree leaves orphan rows that
 * silently re-attach to the next account, because the wizard's administrator
 * gets BID 1.
 *
 * Deliberately NOT available outside dev/test. On a real installation this would
 * be an account-wiping takeover primitive, and the wizard is designed never to
 * reopen there — {@see AdminResetPasswordCommand} is the supported way back into
 * a locked-out instance.
 */
#[AsCommand(
    name: 'app:setup:reset',
    description: 'Reset this installation to its first-run state so the setup wizard runs again (dev/test only)',
)]
final class SetupResetCommand extends Command
{
    private const ALLOWED_ENVIRONMENTS = ['dev', 'test'];

    private const USER_TABLE = 'BUSER';

    public function __construct(
        private readonly Connection $connection,
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip the confirmation prompt (required with `docker compose exec -T`)')
            ->addOption('keep-policy', null, InputOption::VALUE_NONE, 'Keep the stored access policy instead of resetting the wizard toggles to their defaults')
            ->setHelp(<<<'HELP'
                Wipes every account and clears the first-run flag, then the next page load
                lands on /setup again:

                  <info>php %command.full_name% --force</info>

                Keep the access policy the last run stored, e.g. to check that the wizard
                shows it as the current value:

                  <info>php %command.full_name% --force --keep-policy</info>

                The reset only holds until the backend container restarts: unless the stack
                runs with SEED_DEMO_DATA=false, the entrypoint sees an empty BUSER table and
                loads the demo fixtures again, which closes the wizard.

                Refused outside APP_ENV=dev or test.
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!in_array($this->environment, self::ALLOWED_ENVIRONMENTS, true)) {
            $io->error(sprintf(
                'Refusing to run in APP_ENV=%s. This command deletes every account and reopens the setup wizard, which on a real installation means handing it to whoever loads the URL first.',
                $this->environment,
            ));
            $io->writeln('To get back into a locked-out installation use <info>app:admin:reset-password</info> instead.');

            return Command::FAILURE;
        }

        $userCount = $this->userCount();

        if (!$input->getOption('force')) {
            $io->warning(sprintf(
                'This deletes all %d account(s) on this installation, along with their chats, files, widgets and API keys.',
                $userCount,
            ));

            if (!$io->confirm('Reset to first-run state?', false)) {
                $io->writeln('Nothing changed.');

                return Command::SUCCESS;
            }
        }

        $deletedFrom = $this->wipeUsers();
        $this->clearSetupFlag();

        $keepPolicy = (bool) $input->getOption('keep-policy');
        if (!$keepPolicy) {
            $this->clearStoredAccessPolicy();
        }

        $this->report($io, $userCount, $deletedFrom, $keepPolicy);

        return Command::SUCCESS;
    }

    /**
     * Clears BUSER and everything pointing at it.
     *
     * The child tables are read from information_schema rather than listed here,
     * so a new foreign key cannot quietly leave orphans behind. Several of those
     * keys have no ON DELETE CASCADE, hence the explicit order and the disabled
     * checks for the parent row itself.
     *
     * @return list<string> tables that were cleared, parent last
     */
    private function wipeUsers(): array
    {
        $tables = $this->tablesReferencingUsers();
        $tables[] = self::USER_TABLE;

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($tables as $table) {
                $this->connection->executeStatement(sprintf('DELETE FROM %s', $table));
            }

            // Without this the wizard's administrator would not get BID 1, and
            // anything that hardcodes the first user in a dev fixture drifts.
            $this->connection->executeStatement(sprintf('ALTER TABLE %s AUTO_INCREMENT = 1', self::USER_TABLE));
        } finally {
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        return $tables;
    }

    /** @return list<string> */
    private function tablesReferencingUsers(): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT TABLE_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND REFERENCED_TABLE_NAME = :table
            ORDER BY TABLE_NAME
            SQL;

        $statement = $this->connection->prepare($sql);
        $statement->bindValue('table', self::USER_TABLE);

        /** @var list<string> $tables */
        $tables = array_map('strval', $statement->executeQuery()->fetchFirstColumn());

        return $tables;
    }

    private function clearSetupFlag(): void
    {
        $statement = $this->connection->prepare(
            'DELETE FROM BCONFIG WHERE BOWNERID = :owner AND BGROUP = :group AND BSETTING = :setting'
        );
        $statement->bindValue('owner', SetupConstants::OWNER_ID);
        $statement->bindValue('group', SetupConstants::CONFIG_GROUP);
        $statement->bindValue('setting', SetupConstants::KEY_COMPLETED);
        $statement->executeStatement();
    }

    /**
     * Drops the two switches the wizard's last step wrote, so the next run opens
     * on the shipped defaults instead of the previous answers. An environment
     * variable still wins over both, and the wizard shows those as pinned.
     */
    private function clearStoredAccessPolicy(): void
    {
        $statement = $this->connection->prepare(
            'DELETE FROM BCONFIG WHERE BOWNERID = 0 AND BGROUP = :group AND BSETTING IN (:registration, :guest)'
        );
        $statement->bindValue('group', RegistrationConfig::CONFIG_GROUP);
        $statement->bindValue('registration', RegistrationConfig::KEY_ENABLED);
        $statement->bindValue('guest', GuestChatConfig::KEY_ENABLED);
        $statement->executeStatement();
    }

    private function userCount(): int
    {
        return (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', self::USER_TABLE));
    }

    /**
     * @param list<string> $clearedTables
     */
    private function report(SymfonyStyle $io, int $userCount, array $clearedTables, bool $keptPolicy): void
    {
        $io->success(sprintf(
            'Back to first-run state — %d account(s) removed, setup flag cleared.',
            $userCount,
        ));

        $io->writeln('Cleared: <comment>'.implode('</comment>, <comment>', $clearedTables).'</comment>');
        $io->writeln($keptPolicy
            ? 'Access policy kept, so the wizard opens on the stored values.'
            : 'Access policy reset to defaults.');
        $io->newLine();

        $io->writeln('Open <info>/setup</info> — every other route redirects there, and the API answers 503 SETUP_REQUIRED until the wizard finishes.');
        $io->newLine();

        $io->note('Restarting the backend undoes this unless the stack runs with SEED_DEMO_DATA=false: the entrypoint reloads the demo fixtures into an empty database, and any account closes the wizard.');
    }
}
