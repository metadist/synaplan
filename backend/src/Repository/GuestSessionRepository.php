<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GuestSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GuestSession>
 */
class GuestSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuestSession::class);
    }

    public function save(GuestSession $session, bool $flush = false): void
    {
        $this->getEntityManager()->persist($session);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findBySessionId(string $sessionId): ?GuestSession
    {
        return $this->findOneBy(['sessionId' => $sessionId]);
    }

    public function countActiveSessionsByIp(string $ip): int
    {
        return (int) $this->createQueryBuilder('gs')
            ->select('COUNT(gs.id)')
            ->where('gs.ipAddress = :ip')
            ->andWhere('gs.expires > :now')
            ->setParameter('ip', $ip)
            ->setParameter('now', time())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Sum the message counts across all active (non-expired) guest sessions
     * originating from the given IP address.
     *
     * Used to enforce a per-IP message budget so that closing/reopening
     * incognito windows cannot reset the guest trial quota.
     */
    public function sumActiveMessageCountByIp(string $ip, ?int $excludeSessionId = null): int
    {
        $qb = $this->createQueryBuilder('gs')
            ->select('COALESCE(SUM(gs.messageCount), 0)')
            ->where('gs.ipAddress = :ip')
            ->andWhere('gs.expires > :now')
            ->setParameter('ip', $ip)
            ->setParameter('now', time());

        if (null !== $excludeSessionId) {
            $qb->andWhere('gs.id != :excludeId')
                ->setParameter('excludeId', $excludeSessionId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function deleteExpiredSessions(): int
    {
        return $this->createQueryBuilder('gs')
            ->delete()
            ->where('gs.expires < :now')
            ->setParameter('now', time())
            ->getQuery()
            ->execute();
    }
}
