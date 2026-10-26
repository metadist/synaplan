<?php

declare(strict_types=1);

namespace App\Service\Iam;

use App\Entity\User;
use App\Service\Iam\Erasure\UserDataEraserInterface;
use App\Service\Iam\Erasure\UserErasure;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Deletes an account and everything it owns in one transaction. Each domain
 * erases its own rows ({@see UserDataEraserInterface}); external cleanups the
 * erasers queued run after the commit, and a failing one is logged without
 * reporting the committed deletion as failed.
 */
final readonly class UserDeletionService
{
    /**
     * @param iterable<UserDataEraserInterface> $erasers in erase order (tag priority)
     */
    public function __construct(
        private EntityManagerInterface $em,
        #[AutowireIterator(UserDataEraserInterface::TAG)]
        private iterable $erasers,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws \Throwable if deletion fails; the transaction is rolled back
     */
    public function deleteUser(User $user): void
    {
        $this->erase($user, null);
    }

    /**
     * Deletes the account only if $stillEligible accepts it after its row was
     * read again under a write lock inside the erasure transaction. A
     * concurrent write (a payment arriving) is either seen here or waits for
     * the commit.
     *
     * @param \Closure(User): bool $stillEligible
     *
     * @return bool false when the account no longer qualified and nothing was deleted
     *
     * @throws \Throwable if deletion fails; the transaction is rolled back
     */
    public function deleteUserIf(User $user, \Closure $stillEligible): bool
    {
        return $this->erase($user, $stillEligible);
    }

    /**
     * @param (\Closure(User): bool)|null $stillEligible
     */
    private function erase(User $user, ?\Closure $stillEligible): bool
    {
        $userId = $user->getId();
        $email = $user->getMail();

        $this->logger->info('User deletion initiated', [
            'user_id' => $userId,
            'email' => $email,
        ]);

        $this->em->getConnection()->beginTransaction();

        try {
            if (null !== $stillEligible) {
                $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);
                if (!$stillEligible($user)) {
                    $this->em->getConnection()->rollBack();
                    $this->logger->info('User deletion skipped: the account no longer qualifies', ['user_id' => $userId]);

                    return false;
                }
            }

            $erasure = new UserErasure($userId, $email);
            foreach ($this->erasers as $eraser) {
                $eraser->erase($erasure);
            }

            $this->em->remove($user);
            $this->em->flush();

            $this->em->getConnection()->commit();
        } catch (\Throwable $e) {
            $this->rollBackAfterFailure($userId);

            $this->logger->error('Failed to delete user', [
                'user_id' => $userId,
                'email' => $email,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            throw $e;
        }

        $this->runCleanups($erasure);

        $this->logger->info('User and all related data deleted successfully', [
            'user_id' => $userId,
            'email' => $email,
        ]);

        return true;
    }

    private function rollBackAfterFailure(?int $userId): void
    {
        // A failed flush may already have ended the transaction; rolling back
        // again would replace the real cause with NoActiveTransaction.
        $connection = $this->em->getConnection();
        if (!$connection->isTransactionActive()) {
            return;
        }
        try {
            $connection->rollBack();
        } catch (\Throwable $rollbackError) {
            $this->logger->warning('Rollback after a failed user deletion failed', [
                'user_id' => $userId,
                'error' => $rollbackError->getMessage(),
            ]);
        }
    }

    private function runCleanups(UserErasure $erasure): void
    {
        foreach ($erasure->pendingCleanups() as $cleanup) {
            try {
                $cleanup();
            } catch (\Throwable $e) {
                $this->logger->error('Account deletion cleanup failed after the commit', [
                    'user_id' => $erasure->userId,
                    'exception' => $e,
                ]);
            }
        }
    }
}
