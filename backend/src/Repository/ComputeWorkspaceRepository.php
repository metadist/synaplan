<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ComputeWorkspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ComputeWorkspace>
 */
class ComputeWorkspaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComputeWorkspace::class);
    }

    /**
     * The row the owner may still use: active ones plus expiring ones whose
     * grace has not run out (opening renews them — see activeRow()).
     */
    public function findAccessibleForUser(int $userId): ?ComputeWorkspace
    {
        $found = $this->findOneBy([
            'userId' => $userId,
            'status' => [ComputeWorkspace::STATUS_ACTIVE, ComputeWorkspace::STATUS_EXPIRING],
        ]);

        return $found instanceof ComputeWorkspace ? $found : null;
    }

    /**
     * Active workspaces idle past the TTL. Never-used workspaces (no
     * lastUsed yet) age from creation.
     *
     * @return list<ComputeWorkspace>
     */
    public function findStaleActiveWorkspaces(\DateTimeImmutable $lastUsedBefore): array
    {
        /* @var list<ComputeWorkspace> */
        return $this->createQueryBuilder('w')
            ->where('w.status = :active')
            ->andWhere('COALESCE(w.lastUsed, w.created) < :before')
            ->setParameter('active', ComputeWorkspace::STATUS_ACTIVE)
            ->setParameter('before', $lastUsedBefore)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<ComputeWorkspace>
     */
    public function findExpiredWorkspaces(\DateTimeImmutable $now): array
    {
        /* @var list<ComputeWorkspace> */
        return $this->createQueryBuilder('w')
            ->where('w.status = :expiring')
            ->andWhere('w.expiresAt IS NOT NULL')
            ->andWhere('w.expiresAt <= :now')
            ->setParameter('expiring', ComputeWorkspace::STATUS_EXPIRING)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    /**
     * Locking read for account erasure: it sees a workspace committed after
     * the erasing transaction began, and a creation for the same user waits
     * until that transaction ends.
     *
     * @return list<ComputeWorkspace>
     */
    public function findForUserLocked(int $userId): array
    {
        /* @var list<ComputeWorkspace> */
        return $this->createQueryBuilder('w')
            ->where('w.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();
    }

    public function save(ComputeWorkspace $workspace): void
    {
        $this->getEntityManager()->persist($workspace);
        $this->getEntityManager()->flush();
    }

    /**
     * Saves a new workspace only while its owner exists, read under a shared
     * lock in the same transaction, so a creation that outlives the owner's
     * account deletion stores nothing.
     */
    public function saveForExistingOwner(ComputeWorkspace $workspace): bool
    {
        $em = $this->getEntityManager();

        return $em->getConnection()->transactional(static function (Connection $db) use ($em, $workspace): bool {
            $owner = $db->fetchOne('SELECT BID FROM BUSER WHERE BID = ? LOCK IN SHARE MODE', [$workspace->getUserId()]);
            if (false === $owner) {
                return false;
            }
            $em->persist($workspace);
            $em->flush();

            return true;
        });
    }

    public function remove(ComputeWorkspace $workspace): void
    {
        $this->getEntityManager()->remove($workspace);
        $this->getEntityManager()->flush();
    }
}
