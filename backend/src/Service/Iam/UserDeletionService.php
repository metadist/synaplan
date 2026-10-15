<?php

declare(strict_types=1);

namespace App\Service\Iam;

use App\Entity\User;
use App\Service\Iam\Erasure\UserDataEraserInterface;
use App\Service\Iam\Erasure\UserErasure;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Deletes an account and everything it owns in one transaction. Each domain
 * erases its own rows ({@see UserDataEraserInterface}); external cleanups the
 * erasers queued run after the commit.
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
        $userId = $user->getId();
        $email = $user->getMail();

        $this->logger->info('User deletion initiated', [
            'user_id' => $userId,
            'email' => $email,
        ]);

        $erasure = new UserErasure($userId, $email);
        $this->em->getConnection()->beginTransaction();

        try {
            foreach ($this->erasers as $eraser) {
                $eraser->erase($erasure);
            }

            $this->em->remove($user);
            $this->em->flush();

            $this->em->getConnection()->commit();

            foreach ($erasure->pendingCleanups() as $cleanup) {
                $cleanup();
            }

            $this->logger->info('User and all related data deleted successfully', [
                'user_id' => $userId,
                'email' => $email,
            ]);
        } catch (\Throwable $e) {
            $this->em->getConnection()->rollBack();

            $this->logger->error('Failed to delete user', [
                'user_id' => $userId,
                'email' => $email,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            throw $e;
        }
    }
}
