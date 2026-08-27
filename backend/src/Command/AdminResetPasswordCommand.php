<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Admin\BootstrapAdminConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Sets a new password for a local account, and optionally promotes it.
 *
 * The way back into a self-hosted instance whose administrator password is lost.
 * `app:bootstrap-admin` cannot do this on purpose — it is a no-op once an
 * administrator exists, so the deployment secret never doubles as a permanent
 * reset mechanism — and the in-app "forgot password" flow needs a working
 * mailer, which a self-host install often does not have.
 *
 * With `--promote` it also covers the harder case: every administrator is gone
 * (deleted, or demoted), so nobody can reach Admin → Users. The setup wizard
 * deliberately does NOT reopen in that situation, because a reopening wizard on
 * a running instance is a takeover waiting to happen. This command is the
 * replacement, and it requires shell access to the server.
 *
 * Non-interactive by design (flag-driven, like the other app:* commands) so it
 * works over `docker compose exec -T` and in a runbook.
 */
#[AsCommand(
    name: 'app:admin:reset-password',
    description: 'Sets a new password for a local account, optionally promoting it to administrator',
)]
final class AdminResetPasswordCommand extends Command
{
    /**
     * 24 hex characters, same shape the AWS first-boot script generates. Long
     * enough to be unguessable, short enough to retype from a terminal, and past
     * {@see BootstrapAdminConfiguration::COMPOSITION_WAIVER_LENGTH}, so it needs
     * no character-class dance to satisfy the rules it is checked against.
     */
    private const GENERATED_PASSWORD_BYTES = 12;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address of the account')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, sprintf(
                'The new password (%d-%d characters; below %d it also needs an uppercase letter, a lowercase letter and a number)',
                BootstrapAdminConfiguration::MINIMUM_PASSWORD_LENGTH,
                BootstrapAdminConfiguration::MAXIMUM_PASSWORD_LENGTH,
                BootstrapAdminConfiguration::COMPOSITION_WAIVER_LENGTH,
            ))
            ->addOption('generate', null, InputOption::VALUE_NONE, 'Generate a random password, print it once, and require a change at next sign-in')
            ->addOption('promote', null, InputOption::VALUE_NONE, 'Also make this account an administrator and mark its email verified')
            ->setHelp(<<<'HELP'
                Set a known password for an existing local account:

                  <info>php %command.full_name% admin@example.com --password='Str0ngPass'</info>

                Let the command generate one. It is printed once and must be replaced at
                the next sign-in (enforced server-side, not just in the UI):

                  <info>php %command.full_name% admin@example.com --generate</info>

                Recover an instance that has no administrator left:

                  <info>php %command.full_name% someone@example.com --generate --promote</info>

                A password given with --password follows the same rules as
                BOOTSTRAP_ADMIN_PASSWORD: 8 to 64 characters, and below 16 characters it
                must also contain an uppercase letter, a lowercase letter and a number.

                Accounts managed by an enterprise identity provider are refused: their
                password lives in that provider, not here.
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = trim((string) $input->getArgument('email'));
        $explicitPassword = $input->getOption('password');
        $generate = (bool) $input->getOption('generate');
        $promote = (bool) $input->getOption('promote');

        if (null !== $explicitPassword && $generate) {
            $io->error('Use either --password or --generate, not both.');

            return Command::FAILURE;
        }

        if (null === $explicitPassword && !$generate) {
            $io->error('Provide a password with --password, or use --generate to have one created.');

            return Command::FAILURE;
        }

        $user = $this->userRepository->findByEmail($email);
        if (null === $user) {
            $io->error(sprintf('No account found for "%s".', $email));
            $io->writeln('Create the first administrator instead with <info>app:bootstrap-admin</info>, or open /setup on a fresh installation.');

            return Command::FAILURE;
        }

        if ($user->isManagedExternally()) {
            $io->error(sprintf(
                'The account "%s" is managed by %s. Reset its password there — a local password would never be used.',
                $email,
                $user->getAuthProviderName(),
            ));

            return Command::FAILURE;
        }

        $password = $generate ? $this->generatePassword() : (string) $explicitPassword;
        $violation = BootstrapAdminConfiguration::passwordViolation($password, 'The password');
        if (null !== $violation) {
            $io->error($violation);

            return Command::FAILURE;
        }

        $user->setPw($this->passwordHasher->hashPassword($user, $password));

        // A password the operator chose is theirs to keep; one this command
        // invented is a one-time credential that travels through a terminal
        // buffer and a shell history, so it must not survive the first sign-in.
        // PasswordChangeRequiredSubscriber enforces that server-side.
        $user->setMustChangePassword($generate);

        if ($promote) {
            $user->setUserLevel('ADMIN');
            // An unverified address would leave the recovered administrator stuck
            // behind the verification gate, with no mailer to get past it.
            $user->setEmailVerified(true);
        }

        $this->entityManager->flush();

        $this->report($io, $user, $generate ? $password : null, $promote);

        return Command::SUCCESS;
    }

    private function report(SymfonyStyle $io, User $user, ?string $generatedPassword, bool $promoted): void
    {
        $io->success(sprintf(
            'Password updated for %s%s.',
            (string) $user->getMail(),
            $promoted ? ' (now an administrator)' : '',
        ));

        if (null !== $generatedPassword) {
            $io->writeln('New password (shown once):');
            $io->writeln(sprintf('  <comment>%s</comment>', $generatedPassword));
            $io->newLine();
            $io->note('This password has to be replaced at the next sign-in.');
        }

        $io->writeln('Users with a working mailer can also reset their own password at /forgot-password.');
        $io->writeln('Without a configured MAILER_DSN that page cannot deliver anything — this command is the way.');
    }

    private function generatePassword(): string
    {
        return bin2hex(random_bytes(self::GENERATED_PASSWORD_BYTES));
    }
}
