<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CloudWatch;
use App\Entity\ExternalIdentity;
use App\Entity\User;
use App\Repository\ExternalIdentityRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fills one person's cloud with watches so the index can be tried at the cap.
 * Development and test only.
 */
#[AsCommand(name: 'app:cloud-watches:seed-test', description: 'Create watches for the index test (dev and test only)')]
final class SeedCloudWatchesCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ExternalIdentityRepository $identities,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    public static function allowed(string $environment): bool
    {
        return in_array($environment, ['dev', 'test'], true);
    }

    protected function configure(): void
    {
        $this->addOption('email', null, InputOption::VALUE_REQUIRED, 'The owner');
        $this->addOption('count', null, InputOption::VALUE_REQUIRED, 'How many watches', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $environment = $_ENV['APP_ENV'] ?? 'dev';
        if (!self::allowed(is_string($environment) ? $environment : 'dev')) {
            $output->writeln('This command runs only in dev and test.');

            return Command::FAILURE;
        }
        $email = $input->getOption('email');
        $count = (int) $input->getOption('count');
        if (!is_string($email) || $count < 1) {
            $output->writeln('Pass --email and a positive --count.');

            return Command::FAILURE;
        }
        $user = $this->users->findOneBy(['mail' => $email]);
        if (!$user instanceof User) {
            $output->writeln('No such person.');

            return Command::FAILURE;
        }
        $identity = null;
        foreach ($this->identities->findByUserId((int) $user->getId()) as $row) {
            if ('' !== $row->getInstanceId()) {
                $identity = $row;
                break;
            }
        }
        if (!$identity instanceof ExternalIdentity) {
            $output->writeln('That person has no cloud link.');

            return Command::FAILURE;
        }
        for ($i = 0; $i < $count; ++$i) {
            $watch = new CloudWatch((int) $user->getId(), CloudWatch::KIND_FOLDER, 'seed-'.$i.'-'.bin2hex(random_bytes(3)));
            $watch->setIdentityId((int) $identity->getId());
            $watch->setAction(CloudWatch::ACTION_SEARCHABLE);
            $watch->setPreset(CloudWatch::PRESET_SEARCHABLE);
            $watch->setTargetPath('Seed '.$i);
            $this->entityManager->persist($watch);
        }
        $this->entityManager->flush();
        $output->writeln(sprintf('Created %d watch(es).', $count));

        return Command::SUCCESS;
    }
}
