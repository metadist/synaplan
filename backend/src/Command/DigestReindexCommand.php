<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\MessageDigestRepository;
use App\Repository\UserRepository;
use App\Service\Digest\MessageDigestService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Rebuild the Qdrant `user_message_digests` collection from the
 * authoritative MariaDB rows — the recovery path after an embedding-model
 * change or a lost/corrupted Qdrant volume.
 *
 * Point ids are deterministic ({@see MessageDigestService::qdrantPointId}),
 * so re-indexing overwrites the previous points in place; the command is
 * idempotent and safe to re-run after an interruption.
 */
#[AsCommand(
    name: 'app:digest:reindex',
    description: 'Rebuild the Qdrant digest index from MariaDB (LIVE embedding calls, self-locking)'
)]
final class DigestReindexCommand extends Command
{
    private const LOCK_TTL_SECONDS = 7200;
    private const DEFAULT_PAGE_SIZE = 100;

    public function __construct(
        private readonly MessageDigestRepository $digestRepository,
        private readonly UserRepository $userRepository,
        private readonly MessageDigestService $digestService,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Re-index only this user id')
            ->addOption('all-users', null, InputOption::VALUE_NONE, 'Re-index every user with active digests')
            ->addOption('page-size', null, InputOption::VALUE_REQUIRED, 'Digests hydrated per page', (string) self::DEFAULT_PAGE_SIZE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $userOption = $input->getOption('user');
        $allUsers = (bool) $input->getOption('all-users');

        if (!$allUsers && !is_numeric($userOption)) {
            $io->error('Pass --user=<id> or --all-users (embedding every digest again costs real model calls).');

            return Command::INVALID;
        }

        $lock = $this->lockFactory->createLock('message-digest-reindex', self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            $io->writeln('Another digest reindex is still in progress, skipping.');

            return Command::SUCCESS;
        }

        $pageSize = max(10, (int) $input->getOption('page-size'));
        $totals = ['users' => 0, 'reindexed' => 0, 'failed' => 0];

        try {
            $userIds = $allUsers
                ? $this->digestRepository->findDistinctActiveUserIds()
                : [(int) $userOption];

            foreach ($userIds as $userId) {
                $user = $this->userRepository->find($userId);
                if (null === $user) {
                    $io->warning(sprintf('User %d not found, skipping.', $userId));
                    continue;
                }

                ++$totals['users'];
                $afterId = 0;
                while (true) {
                    $page = $this->digestRepository->findActiveForUserAfterId($userId, $afterId, $pageSize);
                    if ([] === $page) {
                        break;
                    }

                    foreach ($page as $digest) {
                        if ($this->digestService->mirrorToQdrant($user, $digest)) {
                            ++$totals['reindexed'];
                        } else {
                            ++$totals['failed'];
                        }
                        $afterId = $digest->getId();
                    }

                    $io->writeln(sprintf(
                        'user %d: %d points rebuilt so far (%d failed)',
                        $userId,
                        $totals['reindexed'],
                        $totals['failed'],
                    ), OutputInterface::VERBOSITY_VERBOSE);
                }
            }
        } finally {
            $lock->release();
        }

        $io->success(sprintf(
            'Digest reindex: %d users, %d points rebuilt, %d failed.',
            $totals['users'],
            $totals['reindexed'],
            $totals['failed'],
        ));

        return $totals['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
